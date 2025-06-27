# R3D Post By Mail – Joomla 5 CLI Script

**Author:** Richard Dvorak (<info@r3d.de>)  
**License:** GNU General Public License v3.0 or later  
**Version:** 0.6.3 (2025-06-27)

---

## 📌 What It Does

This CLI script automatically imports and publishes blog posts from a mailbox (via IMAP) into Joomla 5 as `com_content` articles.

- Processes `text/plain` or `text/html` email bodies
- Detects `MORE:` markers and splits content into intro + full text
- Saves attached images into `images/blog/YYYY-MM-DD/`
- Updates all relevant database tables for proper Joomla backend visibility
- Only processes **unread** emails from **approved senders**

---

## ✉️ Example Email Format

SUBJECT: Test Blog 1 (becomes the article title)

EMAIL BODY:
A first paragraph, any length.

MORE: (converted to Joomla's Readmore separator)

Any number of additional paragraphs, any length.

-- NO SIGNATURE! Otherwise it will be included in the article content. --

- Attachment (one image only): `image.jpg` / `photo.webp`
- Allowed formats: `jpg`, `jpeg`, `png`, `gif`, `webp`
- Image will be uploaded to /images/blog/YYYY-MM-DD/
---

## ⚙️ Configuration Required (Lines ~76–81 in script)

Please **edit these values** directly in the PHP script:

| Setting                | Description                             |
| ---------------------- | --------------------------------------- |
| `YOUR_IMAP_SERVERNAME` | IMAP host (default uses SSL 993)        |
| `YOUR_IMAP_USERNAME`   | IMAP login                              |
| `YOUR_IMAP_PASSWORD`   | IMAP password                           |
| `ALLOWED_SENDER_EMAIL` | List of email addresses allowed to post |
| `YOUR_CATEGORY_ID`     | Joomla category ID (e.g. Blog = 14)     |
| `YOUR_USER_ID`         | Joomla user ID to assign as author      |

---

## 🖥 Requirements

- Dedicated email address with IMAP access
- PHP 8.4 CLI
- Joomla 5.x with `com_content` enabled
- Write access to `/images/blog/YYYY-MM-DD/`

---

## 🕓 Cronjob Setup

To automate the import, create a cronjob that runs as your Joomla **web user** (not root!):

```bash
*/5 * * * * web771 /usr/bin/php8.4 /var/www/clients/client221/web771/web/cli/r3dpostbymail.php

❗ If run as root, uploaded files/folders may become undeletable in Joomla.