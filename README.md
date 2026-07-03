# IIoT System Dashboard

A PHP/Python monitoring dashboard for the IIoT servo maintenance system. Built for Major Project.

The dashboard connects directly to the ESP32, polls live data every second, and serves a browser-based dashboard with real-time data updates, fault history, FFT visualisation, and a variety of other features.

> [!WARNING]
> If you do not want to clone this repository, skip Step 1, create each file and copy and paste all the code yourself. You can follow the manual instructions inside my Maintenance Guide.

## Prerequisites

- PHP 8.x with `php-cli` and `php-sqlite3`
- Python 3 with `pymodbus`
- SQLite3

```bash
sudo apt update
sudo apt install php php-cli php-sqlite3 -y
pip3 install pymodbus
```

## Setup

1. Clone the repository:
```bash
git clone https://github.com/YOUR_USERNAME/YOUR_REPO.git
cd YOUR_REPO
```

2. Create the data directory:
```bash
mkdir data
```

3. Open `config.php` and you MUST replace the placeholder values:
```bash
nano config.php
```
- `ESP32_IP` — IP address of your ESP32
- `AUTH_PASSWORD_HASH` — generate a bcrypt hash of your chosen password:
```bash
php -r "echo password_hash('your_password_here', PASSWORD_DEFAULT);"
```
- `AUTH_USERNAME` — your preferred login username

4. Start the poller in one terminal:
```bash
python3 poller.py
```

5. Start the PHP server in a separate terminal:
Change directory (cd) into the directory where you have put all your code files and run:
```bash
php -S 0.0.0.0:8000
```

6. Visit `http://localhost:8000` and log in.
