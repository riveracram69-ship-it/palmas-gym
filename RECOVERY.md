# System Disaster Recovery Procedure

This document outlines the standard operating procedure for restoring the **Palmas Elite Gym** database from a backup in the event of total system failure or when the web administrative interface is inaccessible.

## Prerequisites
- Physical or Remote Desktop access to the Windows Server hosting the XAMPP environment.
- An existing `.sql` backup file from the `/backups` directory.
- `cmd.exe` or `PowerShell` access.

## Recovery Steps

### 1. Locate the Backup File
Navigate to your XAMPP web root where backups are automatically stored:
`C:\xam\htdocs\gym\gym\backups\`

Identify the most recent or relevant `.sql` file (e.g., `gym_backup_20260821_120000.sql`).

### 2. Open Command Prompt
Open the Windows Start Menu, type `cmd`, and press Enter to launch the Command Prompt.

*Note: Do NOT use PowerShell for this unless explicitly wrapping the command in `cmd /c`, as PowerShell output redirection can corrupt the character encoding.*

### 3. Execute the Restore Command
Run the following command to securely restore the backup into the live database. Ensure you replace `[BACKUP_FILENAME.sql]` with the actual filename you wish to restore:

```cmd
"C:\xam\mysql\bin\mysql.exe" -u root gym_management < "C:\xam\htdocs\gym\gym\backups\[BACKUP_FILENAME.sql]"
```

*Note: If your database uses a password, append `-p` to the command. The system will securely prompt you to type the password.*

### 4. Verify Recovery
Once the command finishes executing, verify the system is operational:
1. Open the gym management web portal.
2. Log in using your Administrator credentials.
3. Check the "Member Management" or "Dashboard" pages to confirm data integrity.

## Emergency Database Recreation
If the `gym_management` database was completely dropped or destroyed, you must recreate the empty database before running the restore command above.

```cmd
"C:\xam\mysql\bin\mysql.exe" -u root -e "CREATE DATABASE gym_management;"
```
