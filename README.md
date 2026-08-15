# Gas Agency Complaint Register & CRM Portal

A lightweight, standalone PHP + SQLite Customer Relationship Management (CRM) portal designed for gas agencies (HP Gas, Indane, Bharat Gas) to manage consumer registries, log service complaints, assign delivery agents (vendors), track E-KYC status, and print receipts.

---

## 🚀 Key Features

### 🏢 Multi-Agency & Multi-Branch Management
* **Dynamic Scoping:** Toggle between multiple branches or brand locations (e.g. Indane, HP) from the admin header selector.
* **Role-Based Scope:** Admins can view all branches or select a specific context, while restricted Employees are locked into their assigned branch.
* **Settings Toggle:** Enable or disable Multi-Branch mode directly using radio buttons in the settings dashboard.

### 🖨️ Portable Thermal Printer Support
* **Multi-Format Slips:** Print service slips scaled for **Standard A4**, **80mm (3-inch)**, or **58mm (2-inch)** width mobile/Bluetooth thermal printers.
* **Monospace Receipt Template:** High-contrast monochrome layout optimized for field agents' handheld receipt printers.

### 📊 Interactive Stats Dashboard
* **6 KPI Cards:** Toggle-filterable cards for Total Consumers, SBC, DBC, E-KYC Completed, E-KYC Pending, and Blocked/Suspended consumers.
* **Live Registry Printing:** Print active filtered list views directly with a single click (automatically hides navigation bars and sidebars in the print layout).

### 🔒 Restricted Access & Permissions
* **Granular RBAC:** Customize account permission options (View complaints, Add complaints, Edit, Delete, Map vendors) for employees.
* **Admin Verification:** Secure backend checks prevent unauthorized data uploads, configuration updates, or deletions.

### ⏰ Real-time Header Greeting
* Displays live time, date, and custom dynamic greetings (Good Morning, Afternoon, Evening, Night) with weather/sun/moon indicator icons matching the time of day.

---

## 🛠️ Technology Stack

* **Frontend:** Vanilla JS, CSS3, FontAwesome Icons, SweetAlert2, Chart.js.
* **Backend:** PHP (Single-file server script).
* **Database:** SQLite3 (Self-healing relational migrations).

---

## 💻 How to Run Locally

### 1. Prerequisites
Make sure you have **PHP** installed on your system.

### 2. Startup Server
Clone this repository and run the built-in PHP development server in the repository directory:
```bash
php -S localhost:8000
```
Alternatively, if you are on Windows, simply double-click the **`run-app.bat`** file included in the root directory.

### 3. Open in Browser
Open your browser and navigate to:
```
http://localhost:8000
```

---

## 📂 Project Structure

```
├── gas-agency-standalone.php   # Main application server code (UI & Backend Cases)
├── .gitignore                  # Git exclusions (database, config files, logs)
├── run-app.bat                 # Windows quick launch script
├── start.js                    # Node.js process orchestrator (if applicable)
└── php-bin/                    # Embedded local PHP runtime packages
```
