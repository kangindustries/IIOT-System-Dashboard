# poller.py
import time
import sqlite3
import json
import os
from pymodbus.client import ModbusTcpClient
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
DB_FILE = os.path.join(BASE_DIR, 'data', 'history.db')
LATEST_FILE = os.path.join(BASE_DIR, 'data', 'latest.json')

ESP32_IP = 'YOUR_ESP32_IP'

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

        except Exception as e:
            print(f"Poll error: {e}")

        time.sleep(1)

if __name__ == '__main__':
    poll_loop()
