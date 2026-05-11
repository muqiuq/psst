# PSST = Philipp's Simple Share Tool

- Keep it vanilla
- JS / PHP / CSS / HTML
- Responsive Design
- Use Twitter Bootstrap, check css and js folder in app
- Deployment will be in Apache2 with PHP on Debian => Create htaccess files
- Testing will be done with Container (podman) and compose file (use Container that can work with htaccess)
- Adjust start.sh accordingly (currently from other app)
- Shared functions put into functions.php
- put application in app folder 
- If php or other application commands must be run (node, etc), use podman
- If you want to use a third party tool ask for permission
- Use SQLite Database
- Database Migration must be done automatically (so if database already exists)
- But DB code in db.php and models in models.php

# Backend
manage.php & manage-api.php
- Single Page Application with api (manage-api.php) in the background
- config.php contains all configurable options
- Can CRUD Shares
- A Share can be a single file, a single link or a text
- A Share can be encrypted (E2E, in the browser, with scrypt or similar) with a minimal of 8 characters password
- Login credentials stored in config.php (can only be changed in the file), default is "admin" with password "psst"
- the base URL is configured in config.php and can be a subfolder like "https://example.com/psst"
- the files are stored in a subfolder (default data) that cannot be access directly (htaccess)
- a link to a share will look like this: https://example.com/psst/index.php?id=UUID
- Every share gets a random UUID
- Besides the link to copy paste (add copy paste button) also a QR Code can be generated
- There a two types of QR Codes: Standard (just url) OR Brazilian Goverment check QR Code (check out validator.py)
- A server side secret code can also be defined 



# Frontend
- index.php
- if file is not encrypted it will automatically show the file (no redirection), add all necessary headers (including file type)
- if the file is encrypted show HTML to decrypt and open the file
- if a secret code is defined show a input (centered) with a button. 
- block a IP with more then 50 requests per hour (define in config.php)
- block a IP with more then 10 failed attempts (secret code) per hour. 
