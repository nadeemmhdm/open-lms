# 🛡️ Security Policy (Open LMS)

At **Open LMS**, we take the security of your students and educational data seriously. This document outlines our vulnerability reporting process and the standard security features integrated into this platform.

---

## 🚀 Supported Versions

| Version | Supported |
|---------|-----------|
| **v1.0.x** | ✅ Full Support |
| < v1.0.0 | ❌ No Support |

---

## 🛡️ Integrated Security Features

The Open LMS project includes several built-in security mechanisms to protect against common web threats:

### 1. Database Protection
- **PDO Prepared Statements**: All database interactions use PDO with parameterized queries to prevent **SQL Injection** (SQLi).
- **Escaped Outputs**: All user-generated content is escaped using `htmlspecialchars()` before being rendered to prevent **Cross-Site Scripting** (XSS).

### 2. Session & Access Control
- **Device Fingerprinting**: The system tracks `session_id()` and device type. If a student attempts to login from a second device, the first session is automatically invalidated to prevent **Account Sharing**.
- **Role-Based Access (RBAC)**: Strict separation between `admin`, `sub_admin`, and `student` roles.
- **CSRF Protection**: All forms are protected by a cryptographically secure token to prevent **Cross-Site Request Forgery**.

### 3. AI Data Security
- **API Scrubbing**: The system is designed to use **Bearer Token** authentication for AI requests. 
- **Sanitized Prompts**: Lesson content is stripped of HTML tags before being sent to the AI engine to prevent unauthorized content injection.

---

## 🐛 Reporting a Vulnerability

If you discover any security vulnerability in this project, please follow these steps:

1.  **Do NOT open a public issue** on GitHub. Public disclosure before a fix is available puts all users at risk.
2.  **Contact the Maintainers**: Send a detailed email to [your-security-contact@email.com] with the subject: "Vulnerability Report - Open LMS".
3.  **Include Details**: 
    - Description of the vulnerability.
    - Steps to reproduce it.
    - Any potential impact.

### Our Commitment
We will acknowledge your report within **48 hours** and provide a timeline for a resolution. Once fixed, we will credit you in the next release notes if you wish.

---

*Thank you for helping us keep the future of education secure!*
