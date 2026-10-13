# Studio Anni V2

Current under active development; the successor to a ten year old college project.

Slim 4 JSON API (PHP 8.3+, target 8.4) with a React (Vite) front end. Hosted on Hostinger shared hosting for now and written to move to a self-hosted box.

## Layout

```
public/       document root: index.php, .htaccess, built React in public/app/
src/          PHP, namespace App\ (Kernel, Config, Database, Routes, Http/...)
bin/          CLI scripts (migrate.php now; cron jobs later)
migrations/   numbered plain .sql files
storage/      logs, cache, private uploads (contents are gitignored)
web/          React + Vite source; `npm run build` writes to public/app/
```

On Hostinger the contents of `public/` go in `public_html/` and everything else goes in a sibling `app/` directory above the web root. `public/index.php` detects either layout.

## Back end (run on the Hostinger staging site over SSH)

Set the SSH CLI's PHP version to match the web one first (hPanel's selector only changes the web version).

```
composer2 update            # first run only: no composer.lock yet, so this resolves and writes it. Commit the lock.
composer2 install --no-dev  # afterwards
cp .env.example .env        # fill in DB_*
php bin/migrate.php
```

Check it: `/api/health` returns `{"status":"ok"}`.

## Front end (Steam Deck: install Node with nvm into your home directory)

```
cd web
cp .env.example .env        # point API_PROXY_TARGET at the staging site
npm install
npm run dev                 # proxies /api to API_PROXY_TARGET
npm run build               # writes to ../public/app/
```

## Deploy

Not written yet (Phase 0). Plan: build the front end locally, `git pull` and `composer2 install --no-dev` over SSH, upload `public/app/`.
