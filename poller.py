# poller.py
import time
import sqlite3
import json
import os
import smtplib
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
from pymodbus.client import ModbusTcpClient
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
DB_FILE = os.path.join(BASE_DIR, 'data', 'history.db')
LATEST_FILE = os.path.join(BASE_DIR, 'data', 'latest.json')

ESP32_IP = '192.168.1.70'

SMTP_USERNAME = "REDACTED BY USER"
SMTP_APP_PASSWORD = "REDACTED BY USER"
ALERT_EMAIL_TO = "REDACTED BY USER"
ALERT_DELAY_SECONDS = 60
PENDING_ALERT_FILE = os.path.join(BASE_DIR, 'data', 'pending_alert.json')
FAULT_LABELS = {1: "Sensor out of range", 2: "Servo arm jammed"}
PENDING_SERVICE_ALERT_FILE = os.path.join(BASE_DIR, 'data', 'pending_service_alert.json')
PENDING_PREDICTIVE_ALERT_FILE = os.path.join(BASE_DIR, 'data', 'pending_predictive_alert.json')

def init_db():
    conn = sqlite3.connect(DB_FILE)
    conn.execute('''
        CREATE TABLE IF NOT EXISTS readings (
            timestamp REAL,
            distance REAL,
            angle INTEGER,
            fault_code INTEGER,
            cycle_count INTEGER,
            ema_deviation REAL,
            sweep_speed INTEGER,
            kf_estimate REAL,
            kf_innovation REAL,
            fault_latched INTEGER,
            predictive_alert INTEGER,
	    service_due INTEGER
        )
    ''')
    conn.commit()
    return conn

def send_alert_email(fault_code, fault_label, triggered_at):
    triggered_str = time.strftime('%d %b %Y, %H:%M:%S', time.localtime(triggered_at))

    text_body = (
        f"Hi,\n\n"
        f"A fault was detected and wasn't cancelled within the "
        f"{ALERT_DELAY_SECONDS}-second grace period. Here's a summary.\n\n"
        f"Fault Code   : {fault_code}\n"
        f"Fault Type   : {fault_label}\n"
        f"Triggered At : {triggered_str}\n\n"
        f"Please check the system and dashboard as soon as possible. "
        f"Once resolved, update the Fault History with the details. "
        f"Thanks for keeping things running smoothly.\n\n"
        f"This is an automated notification from the Servo Monitoring System. "
        f"Please don't reply to this email."
    )

    html_body = f"""
    <html>
      <body style="font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'SF Pro Text', Helvetica, Arial, sans-serif; color: #1d1d1f; background: #f5f5f7; padding: 40px 20px; margin:0; -webkit-font-smoothing: antialiased;">
        <div style="max-width: 460px; margin: 0 auto; background: #ffffff; border-radius: 18px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.04);">

          <div style="padding: 32px 32px 28px; background: #FF3B30;">
            <p style="font-size: 12px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: rgba(255,255,255,0.85); margin: 0 0 6px;">
              Servo Monitoring System
            </p>
            <h1 style="font-size: 22px; font-weight: 600; letter-spacing: -0.01em; color: #ffffff; margin: 0; line-height: 1.3;">
              A fault needs your attention.
            </h1>
          </div>

          <div style="padding: 28px 32px 0;">
            <p style="font-size: 15px; line-height: 1.55; color: #1d1d1f; margin: 0 0 8px;">
              Hi,
            </p>
            <p style="font-size: 15px; line-height: 1.55; color: #1d1d1f; margin: 0 0 28px;">
              A fault was detected and wasn't cancelled within the {ALERT_DELAY_SECONDS}-second grace period. Here's a summary.
            </p>
          </div>

          <div style="padding: 0 32px;">
            <table style="width: 100%; border-collapse: collapse;">
              <tr>
                <td style="font-size: 13px; color: #86868b; padding: 14px 0; border-top: 1px solid #e8e8ed; border-bottom: 1px solid #e8e8ed;">Fault code</td>
                <td style="font-size: 15px; font-weight: 600; color: #1d1d1f; padding: 14px 0; border-top: 1px solid #e8e8ed; border-bottom: 1px solid #e8e8ed; text-align: right;">{fault_code}</td>
              </tr>
              <tr>
                <td style="font-size: 13px; color: #86868b; padding: 14px 0; border-bottom: 1px solid #e8e8ed;">Fault type</td>
                <td style="font-size: 15px; font-weight: 500; color: #1d1d1f; padding: 14px 0; border-bottom: 1px solid #e8e8ed; text-align: right;">{fault_label}</td>
              </tr>
              <tr>
                <td style="font-size: 13px; color: #86868b; padding: 14px 0;">Triggered</td>
                <td style="font-size: 15px; font-weight: 500; color: #1d1d1f; padding: 14px 0; text-align: right;">{triggered_str}</td>
              </tr>
            </table>
          </div>

          <div style="padding: 28px 32px 32px;">
            <p style="font-size: 14px; line-height: 1.6; color: #515154; margin: 0;">
              Please check the system and dashboard as soon as possible. Once resolved, update the Fault History with the details. Thanks for keeping things running smoothly.
            </p>
          </div>

          <div style="padding: 18px 32px; background: #fafafa; border-top: 1px solid #e8e8ed;">
            <p style="font-size: 11px; line-height: 1.5; color: #a1a1a6; margin: 0;">
              This is an automated notification from the Servo Monitoring System. Please don't reply to this email.
            </p>
          </div>

        </div>
      </body>
    </html>
    """

    msg = MIMEMultipart("alternative")
    msg["Subject"] = f"A fault needs your attention — {fault_label}"
    msg["From"] = SMTP_USERNAME
    msg["To"] = ALERT_EMAIL_TO
    msg.attach(MIMEText(text_body, "plain"))
    msg.attach(MIMEText(html_body, "html"))

    with smtplib.SMTP("smtp.gmail.com", 587) as server:
        server.starttls()
        server.login(SMTP_USERNAME, SMTP_APP_PASSWORD)
        server.send_message(msg)

def send_service_alert_email(cycle_count, triggered_at):
    triggered_str = time.strftime('%d %b %Y, %H:%M:%S', time.localtime(triggered_at))

    text_body = (
        f"Hi,\n\n"
        f"A scheduled service is due and the email wasn't cancelled within the "
        f"{ALERT_DELAY_SECONDS}-second grace period. Here's a summary.\n\n"
        f"Activity Type : Servicing Due\n"
        f"Cycle Count   : {cycle_count}\n"
        f"Triggered At  : {triggered_str}\n\n"
        f"Please check the system and dashboard as soon as possible. "
        f"Once resolved, update the Fault & Service History with the details. "
        f"Thanks for keeping things running smoothly.\n\n"
        f"This is an automated notification from the Servo Monitoring System. "
        f"Please don't reply to this email."
    )

    html_body = f"""
    <html>
      <body style="font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'SF Pro Text', Helvetica, Arial, sans-serif; color: #1d1d1f; background: #f5f5f7; padding: 40px 20px; margin:0; -webkit-font-smoothing: antialiased;">
        <div style="max-width: 460px; margin: 0 auto; background: #ffffff; border-radius: 18px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.04);">

          <div style="padding: 32px 32px 28px; background: #FF9500;">
            <p style="font-size: 12px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: rgba(255,255,255,0.85); margin: 0 0 6px;">
              Servo Monitoring System
            </p>
            <h1 style="font-size: 22px; font-weight: 600; letter-spacing: -0.01em; color: #ffffff; margin: 0; line-height: 1.3;">
              A service is due.
            </h1>
          </div>

          <div style="padding: 28px 32px 0;">
            <p style="font-size: 15px; line-height: 1.55; color: #1d1d1f; margin: 0 0 8px;">
              Hi,
            </p>
            <p style="font-size: 15px; line-height: 1.55; color: #1d1d1f; margin: 0 0 28px;">
              A scheduled service is due and wasn't cancelled within the {ALERT_DELAY_SECONDS}-second grace period. Here's a summary.
            </p>
          </div>

          <div style="padding: 0 32px;">
            <table style="width: 100%; border-collapse: collapse;">
              <tr>
                <td style="font-size: 13px; color: #86868b; padding: 14px 0; border-top: 1px solid #e8e8ed; border-bottom: 1px solid #e8e8ed;">Activity type</td>
                <td style="font-size: 15px; font-weight: 600; color: #1d1d1f; padding: 14px 0; border-top: 1px solid #e8e8ed; border-bottom: 1px solid #e8e8ed; text-align: right;">Servicing Due</td>
              </tr>
              <tr>
                <td style="font-size: 13px; color: #86868b; padding: 14px 0; border-bottom: 1px solid #e8e8ed;">Cycle count</td>
                <td style="font-size: 15px; font-weight: 500; color: #1d1d1f; padding: 14px 0; border-bottom: 1px solid #e8e8ed; text-align: right;">{cycle_count}</td>
              </tr>
              <tr>
                <td style="font-size: 13px; color: #86868b; padding: 14px 0;">Triggered</td>
                <td style="font-size: 15px; font-weight: 500; color: #1d1d1f; padding: 14px 0; text-align: right;">{triggered_str}</td>
              </tr>
            </table>
          </div>

          <div style="padding: 28px 32px 32px;">
            <p style="font-size: 14px; line-height: 1.6; color: #515154; margin: 0;">
              Please check the system and dashboard as soon as possible. Once resolved, update the Fault &amp; Service History with the details. Thanks for keeping things running smoothly.
            </p>
          </div>

          <div style="padding: 18px 32px; background: #fafafa; border-top: 1px solid #e8e8ed;">
            <p style="font-size: 11px; line-height: 1.5; color: #a1a1a6; margin: 0;">
              This is an automated notification from the Servo Monitoring System. Please don't reply to this email.
            </p>
          </div>

        </div>
      </body>
    </html>
    """

    msg = MIMEMultipart("alternative")
    msg["Subject"] = "A service is due — Servicing Due"
    msg["From"] = SMTP_USERNAME
    msg["To"] = ALERT_EMAIL_TO
    msg.attach(MIMEText(text_body, "plain"))
    msg.attach(MIMEText(html_body, "html"))

    with smtplib.SMTP("smtp.gmail.com", 587) as server:
        server.starttls()
        server.login(SMTP_USERNAME, SMTP_APP_PASSWORD)
        server.send_message(msg)

def send_predictive_alert_email(kf_innovation, triggered_at):
    triggered_str = time.strftime('%d %b %Y, %H:%M:%S', time.localtime(triggered_at))

    text_body = (
        f"Hi,\n\n"
        f"A predictive maintenance alert was triggered and wasn't cancelled within the "
        f"{ALERT_DELAY_SECONDS}-second grace period. Here's a summary.\n\n"
        f"Activity Type    : Predictive Alert\n"
        f"Innovation Value : {kf_innovation:.1f} cm\n"
        f"Triggered At     : {triggered_str}\n\n"
        f"Please check the system and dashboard as soon as possible. "
        f"Thanks for keeping things running smoothly.\n\n"
        f"This is an automated notification from the Servo Monitoring System. "
        f"Please don't reply to this email."
    )

    html_body = f"""
    <html>
      <body style="font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'SF Pro Text', Helvetica, Arial, sans-serif; color: #1d1d1f; background: #f5f5f7; padding: 40px 20px; margin:0; -webkit-font-smoothing: antialiased;">
        <div style="max-width: 460px; margin: 0 auto; background: #ffffff; border-radius: 18px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.04);">

          <div style="padding: 32px 32px 28px; background: #007AFF;">
            <p style="font-size: 12px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: rgba(255,255,255,0.85); margin: 0 0 6px;">
              Servo Monitoring System
            </p>
            <h1 style="font-size: 22px; font-weight: 600; letter-spacing: -0.01em; color: #ffffff; margin: 0; line-height: 1.3;">
              A predictive alert was triggered.
            </h1>
          </div>

          <div style="padding: 28px 32px 0;">
            <p style="font-size: 15px; line-height: 1.55; color: #1d1d1f; margin: 0 0 8px;">
              Hi,
            </p>
            <p style="font-size: 15px; line-height: 1.55; color: #1d1d1f; margin: 0 0 28px;">
              A predictive maintenance alert was triggered and wasn't cancelled within the {ALERT_DELAY_SECONDS}-second grace period. Here's a summary.
            </p>
          </div>

          <div style="padding: 0 32px;">
            <table style="width: 100%; border-collapse: collapse;">
              <tr>
                <td style="font-size: 13px; color: #86868b; padding: 14px 0; border-top: 1px solid #e8e8ed; border-bottom: 1px solid #e8e8ed;">Activity type</td>
                <td style="font-size: 15px; font-weight: 600; color: #1d1d1f; padding: 14px 0; border-top: 1px solid #e8e8ed; border-bottom: 1px solid #e8e8ed; text-align: right;">Predictive Alert</td>
              </tr>
              <tr>
                <td style="font-size: 13px; color: #86868b; padding: 14px 0; border-bottom: 1px solid #e8e8ed;">Innovation value</td>
                <td style="font-size: 15px; font-weight: 500; color: #1d1d1f; padding: 14px 0; border-bottom: 1px solid #e8e8ed; text-align: right;">{kf_innovation:.1f} cm</td>
              </tr>
              <tr>
                <td style="font-size: 13px; color: #86868b; padding: 14px 0;">Triggered</td>
                <td style="font-size: 15px; font-weight: 500; color: #1d1d1f; padding: 14px 0; text-align: right;">{triggered_str}</td>
              </tr>
            </table>
          </div>

          <div style="padding: 28px 32px 32px;">
            <p style="font-size: 14px; line-height: 1.6; color: #515154; margin: 0;">
              Please check the system and dashboard as soon as possible. Thanks for keeping things running smoothly.
            </p>
          </div>

          <div style="padding: 18px 32px; background: #fafafa; border-top: 1px solid #e8e8ed;">
            <p style="font-size: 11px; line-height: 1.5; color: #a1a1a6; margin: 0;">
              This is an automated notification from the Servo Monitoring System. Please don't reply to this email.
            </p>
          </div>

        </div>
      </body>
    </html>
    """

    msg = MIMEMultipart("alternative")
    msg["Subject"] = "A predictive alert needs your attention"
    msg["From"] = SMTP_USERNAME
    msg["To"] = ALERT_EMAIL_TO
    msg.attach(MIMEText(text_body, "plain"))
    msg.attach(MIMEText(html_body, "html"))

    with smtplib.SMTP("smtp.gmail.com", 587) as server:
        server.starttls()
        server.login(SMTP_USERNAME, SMTP_APP_PASSWORD)
        server.send_message(msg)

def log_notification_sent(fault_timestamp, fault_code, sent_timestamp):
    conn = sqlite3.connect(DB_FILE)
    conn.execute('''
        INSERT INTO notification_log (fault_timestamp, fault_code, sent_timestamp, sent_to)
        VALUES (?, ?, ?, ?)
    ''', (fault_timestamp, fault_code, sent_timestamp, ALERT_EMAIL_TO))
    conn.commit()
    conn.close()

def log_service_notification_sent(service_timestamp, cycle_count, sent_timestamp):
    conn = sqlite3.connect(DB_FILE)
    conn.execute('''
        INSERT INTO notification_log (fault_timestamp, fault_code, sent_timestamp, sent_to)
        VALUES (?, ?, ?, ?)
    ''', (service_timestamp, -1, sent_timestamp, ALERT_EMAIL_TO))
    conn.commit()
    conn.close()

def log_predictive_notification_sent(predictive_timestamp, sent_timestamp):
    conn = sqlite3.connect(DB_FILE)
    conn.execute('''
        INSERT INTO notification_log (fault_timestamp, fault_code, sent_timestamp, sent_to)
        VALUES (?, ?, ?, ?)
    ''', (predictive_timestamp, -2, sent_timestamp, ALERT_EMAIL_TO))
    conn.commit()
    conn.close()

def check_and_send_pending_alert(current_fault_code, current_fault_latched, timestamp):
    pending = None
    try:
        with open(PENDING_ALERT_FILE, "r") as f:
            pending = json.load(f)
    except (FileNotFoundError, json.JSONDecodeError):
        pending = None

    if pending is not None and not current_fault_latched:
        try:
            os.remove(PENDING_ALERT_FILE)
        except FileNotFoundError:
            pass
        return

    if current_fault_latched and current_fault_code != 0 and pending is None:
        pending = {"fault_code": current_fault_code, "triggered_at": timestamp,
                   "cancelled": False, "sent": False}
        with open(PENDING_ALERT_FILE, "w") as f:
            json.dump(pending, f)
        return

    if pending is not None:
        if pending.get("sent", False) or pending.get("cancelled", False):
            return

        elapsed = timestamp - pending["triggered_at"]
        if elapsed >= ALERT_DELAY_SECONDS:
            label = FAULT_LABELS.get(pending["fault_code"], "Unknown fault")
            try:
                send_alert_email(pending["fault_code"], label, pending["triggered_at"])
                log_notification_sent(pending["triggered_at"], pending["fault_code"], timestamp)
            except Exception as e:
                print(f"[ALERT ERROR] Failed to send alert email: {e}")
                return
            pending["sent"] = True
            with open(PENDING_ALERT_FILE, "w") as f:
                json.dump(pending, f)

def check_and_send_pending_service_alert(current_service_due, current_cycle_count, timestamp):
    pending = None
    try:
        with open(PENDING_SERVICE_ALERT_FILE, "r") as f:
            pending = json.load(f)
    except (FileNotFoundError, json.JSONDecodeError):
        pending = None

    if pending is not None and not current_service_due:
        try:
            os.remove(PENDING_SERVICE_ALERT_FILE)
        except FileNotFoundError:
            pass
        return

    if current_service_due and pending is None:
        pending = {"cycle_count": current_cycle_count, "triggered_at": timestamp,
                   "cancelled": False, "sent": False}
        with open(PENDING_SERVICE_ALERT_FILE, "w") as f:
            json.dump(pending, f)
        return

    if pending is not None:
        if pending.get("sent", False) or pending.get("cancelled", False):
            return

        elapsed = timestamp - pending["triggered_at"]
        if elapsed >= ALERT_DELAY_SECONDS:
            try:
                send_service_alert_email(pending["cycle_count"], pending["triggered_at"])
                log_service_notification_sent(pending["triggered_at"], pending["cycle_count"], timestamp)
            except Exception as e:
                print(f"[ALERT ERROR] Failed to send service alert email: {e}")
                return
            pending["sent"] = True
            with open(PENDING_SERVICE_ALERT_FILE, "w") as f:
                json.dump(pending, f)

def check_and_send_pending_predictive_alert(current_predictive_alert, current_kf_innovation, timestamp):
    pending = None
    try:
        with open(PENDING_PREDICTIVE_ALERT_FILE, "r") as f:
            pending = json.load(f)
    except (FileNotFoundError, json.JSONDecodeError):
        pending = None

    if pending is not None and not current_predictive_alert:
        try:
            os.remove(PENDING_PREDICTIVE_ALERT_FILE)
        except FileNotFoundError:
            pass
        return

    if current_predictive_alert and pending is None:
        pending = {"kf_innovation": current_kf_innovation, "triggered_at": timestamp,
                   "cancelled": False, "sent": False}
        with open(PENDING_PREDICTIVE_ALERT_FILE, "w") as f:
            json.dump(pending, f)
        return

    if pending is not None:
        if pending.get("sent", False) or pending.get("cancelled", False):
            return

        elapsed = timestamp - pending["triggered_at"]
        if elapsed >= ALERT_DELAY_SECONDS:
            try:
                send_predictive_alert_email(pending["kf_innovation"], pending["triggered_at"])
                log_predictive_notification_sent(pending["triggered_at"], timestamp)
            except Exception as e:
                print(f"[ALERT ERROR] Failed to send predictive alert email: {e}")
                return
            pending["sent"] = True
            with open(PENDING_PREDICTIVE_ALERT_FILE, "w") as f:
                json.dump(pending, f)

def poll_loop():
    print("STEP 1: connecting client")
    client = ModbusTcpClient(ESP32_IP, port=502, timeout=3)
    client.connect()
    print("STEP 2: client connected")
    conn = init_db()
    print("STEP 3: db initialized")

    while True:
        try:
            print("STEP 4: about to read input registers")
            ireg = client.read_input_registers(0, count=18)
            print("STEP 5: input registers read OK")
            hreg = client.read_holding_registers(2, count=1)
            print("STEP 6: holding register read OK")
            ists = client.read_discrete_inputs(0, count=8)
            print("STEP 7: discrete inputs read OK")

            data = {
                "timestamp": time.time(),
                "distance": ireg.registers[0] / 10.0,
                "angle": ireg.registers[1],
                "fault_code": ireg.registers[2],
                "cycle_count": ireg.registers[3],
                "ema_deviation": ireg.registers[4] / 10.0,
                "sweep_speed": hreg.registers[0],
                "kf_estimate": ireg.registers[15] / 10.0,
                "kf_innovation": ireg.registers[16] / 10.0,
                "fault_latched": int(ists.bits[0]),
                "predictive_alert": int(ists.bits[2]),
		"service_due": int(ists.bits[1])
            }
            print("STEP 8: data dict built")

            conn.execute('''
                INSERT INTO readings VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ''', (
                data["timestamp"], data["distance"], data["angle"],
                data["fault_code"], data["cycle_count"], data["ema_deviation"],
                data["sweep_speed"], data["kf_estimate"], data["kf_innovation"],
                data["fault_latched"], data["predictive_alert"], data["service_due"]
            ))
            conn.commit()
            print("STEP 9: db write OK")

            with open(LATEST_FILE, 'w') as f:
                json.dump(data, f)
            print("STEP 10: json write OK")

            check_and_send_pending_alert(data["fault_code"], data["fault_latched"], data["timestamp"])
            check_and_send_pending_service_alert(data["service_due"], data["cycle_count"], data["timestamp"])
            check_and_send_pending_predictive_alert(data["predictive_alert"], data["kf_innovation"], data["timestamp"])

        except Exception as e:
            print(f"Poll error: {e}")

        time.sleep(1)

if __name__ == '__main__':
    poll_loop()