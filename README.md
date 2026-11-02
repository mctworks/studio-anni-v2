# Studio Anni
 
The website for [Studio Anni](https://studioanni.com), the home of Atlanta-based artist (and my wife) Anni Thompson and her catalog of detailed acrylic paintings, from pet portraits to landscapes, space and fantasy. The first version (currently online) was developed back in 2017, when I was finishing up my degree in Web Development.
 
This repository is Version 2: a ground-up rebuild of the original site. The goals are a modern look, faster pages, a cleaner back end, new ways for customers to engage with Anni's work (accounts, commissions, comments and a guestbook), and to eliminate a lot of the time spent maintaining the site in general.
 
Version 2 is still under active development and is not live yet. It's expected to go live in December 2026.
 
## Built with
 
- **Back end:** PHP 8.5 with [Slim 4](https://www.slimframework.com/) as a JSON API, PHP-DI for dependency injection, and MariaDB/MySQL through PDO
- **Front end:** [React](https://react.dev/) with React Router, built with [Vite](https://vite.dev/), with [GSAP](https://gsap.com/) for animation
- **Hosting:** Hostinger shared hosting, written so it can move to a self-hosted server later (no Redis, queues or other services that shared hosting can't run)
## What works today
 
**For visitors**
- A home page with Anni's latest work, recent commissions, pieces for purchase, work currently on display, and her Bluesky feed
- Galleries for each collection (animals, nature and space, people, fantasy, landscapes and cityscapes, and discounted pieces), each opened by an animated slideshow banner
- A Past Works archive of sold and in-studio pieces, with search, category filter and paging
- Individual piece pages, all backed by a public catalog API
- Light and dark themes
- A shipping quote engine that packs an order into boxes and prices it through USPS, with insurance worked out from the real value of each piece
**For Anni: The new admin interface: MIMOSA**
- Sign-in with a password plus an authenticator-app code (or a one-time recovery code)
- Add, edit, soft-delete and restore pieces, including categories, prices, sales and status
- Image uploads that are resized automatically, with a drag-and-resize watermark editor (adjustable logo, size, position and opacity) that keeps the unwatermarked original so a watermark can be changed or removed later
- Brute-force throttling, CSRF protection, an audit log of every change, and a kill switch that hides the whole admin area
## Still to come
 
Customer accounts, the shopping cart and checkout, commission requests and payments, piece comments, the guestbook, an About page and a contact form.