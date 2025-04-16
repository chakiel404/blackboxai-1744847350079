
Built by https://www.blackbox.ai

---

# CORS API Management System

## Project Overview
This project is an API management system that handles various operations including user authentication, teacher management, schedule management, materials upload, grading, and submission management. The system is built using PHP and uses JWT for authentication. It is designed to support various user roles including Admin, Teacher (Guru), and Student (Siswa).

## Installation
To set up this project locally, follow these steps:

1. **Clone the Repository**
   ```bash
   git clone <repository-url>
   cd <repository-directory>
   ```

2. **Install PHP and Composer**
   Make sure you have PHP installed on your system. Additionally, if you're using packages that require Composer, install Composer globally.

3. **Set Up Database**
   Create a MySQL database and import the necessary SQL schema files to set up tables and relationships as needed for the project.

4. **Configure Environment**
   Update the `config/database.php` file with your database credentials:
   ```php
   $this->conn = new mysqli("hostname", "username", "password", "database");
   ```

5. **Set Up Virtual Host (Optional)**
   If using a local server, set up a virtual host for easier access.

## Usage
To use the API:
1. Start your PHP server using the command:
   ```bash
   php -S localhost:8000
   ```
2. Access the API endpoints via a REST client like Postman or directly from your frontend application.

3. Depending on the endpoint, you will need to include a Bearer token for authorization.

## Features
- **User Authentication**: Login and Logout functionalities with JWT.
- **Teacher Management**: Create, Read, Update, and Delete teacher records.
- **Schedule Management**: Manage class schedules with CRUD capabilities.
- **Materials Management**: Upload and manage educational materials.
- **Grading System**: Assign and manage grades for students.
- **Submission Management**: Handle assignments submission by students, including upload files.
  
## Dependencies
This project requires the following PHP extensions:
- `mysqli`
- `json`
- `fileinfo`
- `openssl`

To ensure smooth operation, ensure these extensions are enabled in your `php.ini`.

## Project Structure
```
/project-root
|-- /config              # Configuration files
|   |-- cors.php        # CORS settings
|   |-- database.php     # Database connection setup
|   |-- jwt.php          # JWT token management
|-- /middleware          # Middleware functions for authentication
|   |-- auth.php         # Authentication middleware
|   |-- validation_helper.php # Helper functions for validation
|-- /logs                # Error logs
|-- /storage             # File storage for materials
|-- /api                 # API endpoint handlers
|   |-- auth.php         # User authentication endpoints
|   |-- guru.php         # Teacher management endpoints
|   |-- jadwal.php       # Schedule management endpoints
|   |-- kelas.php        # Class management endpoints
|   |-- mapel.php        # Subject management endpoints
|   |-- nillai.php       # Grading management endpoints
|   |-- pengumpulan.php   # Submission management endpoints
|   |-- materi.php       # Material management endpoints
|   |-- logout.php       # Logout endpoint
|-- index.php            # Entry point
```

## Contributing
Contributions are welcome! If you would like to contribute to this project, please fork the repository and submit a pull request with your changes.

## License
This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details. 

## Acknowledgments
- PHP and MySQL for backend development.
- JWT for token-based authentication.
- Libraries for CORS and error handling.

Feel free to reach out if you have any questions or suggestions!