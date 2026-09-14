# Git → SiteGround WordPress

Production WordPress at **thelnkup.com** stays an existing SiteGround install. This monorepo also contains Next.js and Expo — **do not clone the repo into `public_html`**.

GitHub (or a laptop script) copies only:

- `wp-content/plugins/lnkup-marketplace/`
- `wp-content/themes/lnkup-theme/`

Then the server runs `composer install --no-dev` in the plugin folder (Stripe + dompdf). Vercel and EAS stay on their own pipelines.

## What never gets uploaded

- WordPress core, `wp-config.php`, `wp-content/uploads`
- `node_modules`, plugin `vendor/` (rebuilt on the server), `.env`, secrets
- `lnk-up-marketplace-ui/`, `lnk-up-marketplace-mobile/`

## GitHub secrets

Repo → **Settings → Secrets and variables → Actions**:

| Secret | Example / notes |
|--------|-----------------|
| `SG_SSH_HOST` | `ssh.thelnkup.com` |
| `SG_SSH_PORT` | `18765` |
| `SG_SSH_USER` | SiteGround SSH username from Site Tools (not your email) |
| `SG_SSH_KEY` | Full private key PEM (include `-----BEGIN` / `-----END` lines) |
| `SG_WP_ROOT` | `/home/customer/www/thelnkup.com/public_html` (confirmed over SSH; `lnkup-marketplace` and `lnkup-theme` already exist) |

Use an SSH **key**. SiteGround on this host accepts `publickey` only. Do not put the SSH password in the repo, Actions YAML, or docs.

If an SSH password was pasted into chat, **change it in Site Tools** after the key works.

## Add the deploy key on SiteGround

1. Site Tools → **Dev** → **SSH Keys Manager**.
2. Import / add the public key from your machine (`~/.ssh/lnkup_siteground.pub`) or paste the public half of the key stored as `SG_SSH_KEY`.
3. Confirm login:

```bash
ssh -p 18765 -i ~/.ssh/lnkup_siteground USER@ssh.thelnkup.com "pwd; ls wp-content/plugins wp-content/themes"
```

Set `SG_WP_ROOT` to the directory that contains `wp-content` (usually `public_html`).

## Run a deploy

**GitHub Actions (preferred):** Actions → **Deploy WordPress to SiteGround** → Run workflow.

The workflow is `workflow_dispatch` only until the first deploy succeeds. Then you may uncomment the `push` trigger in [`.github/workflows/deploy-wordpress.yml`](../.github/workflows/deploy-wordpress.yml).

**Laptop:**

```powershell
$env:SG_SSH_HOST = "ssh.thelnkup.com"
$env:SG_SSH_PORT = "18765"
$env:SG_SSH_USER = "<Site Tools SSH user>"
$env:SG_WP_ROOT  = "/home/customer/www/thelnkup.com/public_html"
$env:SG_SSH_KEY  = "$HOME\.ssh\lnkup_siteground"
.\scripts\deploy-siteground.ps1
```

## After deploy

- Hard-refresh wp-admin and the dashboards so `?ver=` assets update.
- Confirm `POST /wp-json/lnkup/v1/auth/register` exists after plugin **12.18.39+**.
- Zip upload (`scripts/build-plugin-zip.ps1`) remains a fallback if SSH is down.
