
# ✅ Laravel Deployment Notes for Production Server

## 1. Pre-deploy Checklist
- [ ] Commit and push latest working version from local
- [ ] Commented out notifications? (e.g., mail) — remember to restore later
- [ ] Check `.env` for production configuration

---

## 2. Server Setup Steps
- [ ] Upload project files (`scp`, `git clone`, or deploy via CI/CD)
- [ ] Run:
  ```bash
  composer install --optimize-autoloader --no-dev
  ```

- [ ] Set correct permissions:
  ```bash
  sudo chown -R www-data:www-data storage bootstrap/cache
  chmod -R 775 storage bootstrap/cache
  ```

- [ ] Copy `.env` (if not already):
  ```bash
  cp .env.example .env
  ```

- [ ] Set production values in `.env`:
  - `APP_ENV=production`
  - `APP_DEBUG=false`
  - `QUEUE_CONNECTION=database` *(or redis, etc.)*
  - `MAIL_*` configs (when ready)
  - `LOG_CHANNEL=stack` (or `daily`)

---

## 3. Database & Migrations
- [ ] Run migrations:
  ```bash
  php artisan migrate --force
  ```

- [ ] Seed (if needed):
  ```bash
  php artisan db:seed
  ```

---

## 4. Queue Setup
- [ ] Ensure `queue:table` migration has been run:
  ```bash
  php artisan queue:table && php artisan migrate
  ```

- [ ] Start the queue worker:
  ```bash
  php artisan queue:work --daemon
  ```

- [ ] Optionally set it as a service:
  - Use `Supervisor` or `systemd` to auto-restart the queue

---

## 5. Scheduled Jobs
- [ ] Ensure `cron` is running every minute:
  ```bash
  * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
  ```

---

## 6. Log Email-Only Placeholder (If Mail Not Set Up Yet)
- [ ] When mail not configured, ensure notify() lines are commented and log instead:
  ```php
  // $user->notify(new Something());
  Log::info("Would send notification to {$user->email}");
  ```

- [ ] Add TODO tags so you can grep later:
  ```php
  // TODO: Restore notifications once mail is set up
  ```

---

## 7. Post-Deployment Testing
- [ ] Test transaction flow (send, request, dispute)
- [ ] Test file upload (evidence)
- [ ] Check balances update correctly
- [ ] Confirm scheduled job releases funds
- [ ] Confirm risk scoring works
- [ ] Tail logs:
  ```bash
  tail -f storage/logs/laravel.log
  ```

---

## 8. To Remember Later
- [ ] Restore any `TODO` or temporary logs in place of email
- [ ] Enable full mail support and test SMTP
- [ ] Set up backup jobs (optional)
- [ ] Monitor queue and scheduled job health
