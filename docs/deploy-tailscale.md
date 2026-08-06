# Deploying updates over Tailscale

Use **Tailscale** as a private network between your development machine and the server. Git (or SSH file copy) runs over that link—you do not need to expose SSH on the public internet.

**Project path (local dev):** `C:\xampp\htdocs\CLASS`  
**Stack:** PHP 8+, MySQL/MariaDB — see [03-installation.md](03-installation.md).

---

## How it fits together

```text
[Your PC + Tailscale]  ----100.x or MagicDNS---->  [Server + Tailscale]
         |                                                    |
    git push / ssh                                      git pull / web root
         |                                                    |
    [GitHub optional]                              Apache / IIS + PHP + MySQL
```

| Term | Meaning |
|------|---------|
| **Push (to server)** | Send new commits or files to the machine that runs the portal |
| **Pull (on server)** | On the server, run `git pull` (from GitHub or after a direct push) into the web root |
| **Pull (to PC)** | Rare: `git pull` from a server remote to sync down—only if the server is canonical |

Tailscale provides **reachability** (IP/hostname). **Git** provides **versioning**. They work together via **SSH**.

---

## Prerequisites

- Same Tailscale account (or org) on **both** machines
- **SSH server** on the production host (OpenSSH on Linux; OpenSSH Server on Windows)
- Git installed on PC and server
- On the server: PHP, web server, and MySQL already configured; first-time install done via `install.php` (see installation doc)

---

## Step 1: Install and verify Tailscale

### Server

1. Install from [https://tailscale.com/download](https://tailscale.com/download).
2. Authenticate: `tailscale up` (Linux) or sign in via the Windows/macOS app.
3. In [Tailscale admin → Machines](https://login.tailscale.com/admin/machines), note:
   - **Tailscale IP** (e.g. `100.64.x.x`), or
   - **Machine name** with **MagicDNS** enabled (Admin → DNS → Enable MagicDNS), e.g. `wpu-class.tailabc123.ts.net`.

### Windows PC (XAMPP dev)

1. Install Tailscale for Windows and sign in to the **same** tailnet.
2. Test connectivity:

```powershell
ping wpu-class.tailabc123.ts.net
# or
ping 100.64.x.x
```

3. Test SSH (replace user and host):

```powershell
ssh deploy@wpu-class.tailabc123.ts.net
```

---

## Step 2: SSH keys (recommended)

Avoid typing passwords for every deploy.

```powershell
# Generate once on Windows (skip if you already have id_ed25519)
ssh-keygen -t ed25519 -f $env:USERPROFILE\.ssh\id_ed25519

# Install public key on server (enter password one time)
Get-Content $env:USERPROFILE\.ssh\id_ed25519.pub | ssh deploy@wpu-class.tailabc123.ts.net "mkdir -p ~/.ssh && chmod 700 ~/.ssh && cat >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys"
```

Optional `~/.ssh/config` on Windows (`C:\Users\<You>\.ssh\config`):

```text
Host wpu-class
    HostName wpu-class.tailabc123.ts.net
    User deploy
    IdentityFile ~/.ssh/id_ed25519
```

Then: `ssh wpu-class`

---

## Step 3: Choose a Git workflow

### Workflow A — GitHub (or GitLab) + pull on server (good for teams)

**On your PC** — push to the shared remote as usual:

```powershell
cd C:\xampp\htdocs\CLASS
git add .
git commit -m "Describe the change"
git push origin main
```

**On the server** — pull over SSH via Tailscale:

```powershell
ssh wpu-class "cd /var/www/CLASS && git fetch origin && git pull origin main"
```

First-time on server (clone once):

```bash
cd /var/www
git clone https://github.com/YOUR_ORG/CLASS.git CLASS
cp CLASS/config/config.example.php CLASS/config/config.php
# Edit config.php with production DB and APP_BASE_URL
# Run install.php / upgrade_roles.php in browser once
```

Replace `/var/www/CLASS` with your docroot (e.g. `C:\xampp\htdocs\CLASS` on a Windows server).

---

### Workflow B — Direct `git push` to bare repo on server (no GitHub)

**One-time on server** (Linux example):

```bash
sudo mkdir -p /var/git/CLASS.git /var/www/CLASS
sudo chown -R deploy:deploy /var/git/CLASS.git /var/www/CLASS
cd /var/git/CLASS.git
git init --bare
```

Create `hooks/post-receive` (executable):

```bash
#!/bin/sh
GIT_WORK_TREE=/var/www/CLASS git checkout -f main
```

```bash
chmod +x hooks/post-receive
```

**One-time on server** — initial checkout and config:

```bash
cd /var/www/CLASS
git clone /var/git/CLASS.git .
cp config/config.example.php config/config.php
# edit config.php, run install.php + upgrade_roles.php
```

**On your PC** — add remote using Tailscale host:

```powershell
cd C:\xampp\htdocs\CLASS
git remote add production deploy@wpu-class:/var/git/CLASS.git
git push production main
```

Each push updates `/var/www/CLASS` via the hook. Run migrations after push (see below).

---

## Step 4: After every deploy (CLASS-specific)

| Step | Action |
|------|--------|
| Config | **Never** overwrite `config/config.php` on the server (gitignored). Update it manually when settings change. |
| Schema | Open `upgrade_roles.php` in the browser (or POST as documented in [03-installation.md](03-installation.md)) after pulls that change the database. |
| First install only | `install.php` — not on every update. |
| Uploads | Preserve `uploads/` on the server; do not delete user files when deploying. |
| IIS | If using Windows IIS, keep `web.config` and `uploads/web.config` in place. |

**Example post-pull command** (Linux, adjust paths):

```powershell
ssh wpu-class "cd /var/www/CLASS && git pull origin main && php upgrade_roles.php"
```

If `upgrade_roles.php` must run via browser only, skip the PHP CLI line and hit the URL once:

```text
https://your-internal-or-public-url/upgrade_roles.php
```

Set `APP_BASE_URL` in `config/config.php` correctly for production.

---

## Step 5: Pull updates *from* server to PC (optional)

Use only when the server has commits you need locally (unusual if GitHub is source of truth):

```powershell
cd C:\xampp\htdocs\CLASS
git pull production main
```

Resolve conflicts carefully; prefer GitHub as the single source of truth when possible.

---

## File sync without Git (emergency only)

```powershell
scp -r C:\xampp\htdocs\CLASS\includes deploy@wpu-class:/var/www/CLASS/
```

Prefer Git so you can roll back. Exclude `config/config.php` and large `uploads/` unless intentional.

---

## Security

- In Tailscale **Access controls**, restrict SSH to your admin devices if the tailnet has many users.
- On the server firewall, allow SSH from the **Tailscale interface** rather than the whole internet.
- Do not commit secrets; keep `config/config.php` out of Git (see `.gitignore`).
- Rotate Tailscale and SSH keys if a laptop is lost.

---

## Troubleshooting

| Problem | What to check |
|---------|----------------|
| `ping` fails | Both machines logged into Tailscale; same tailnet; server not suspended |
| SSH timeout | OpenSSH running on server; correct Tailscale IP/hostname; ACLs allow traffic |
| `git pull` auth fails on server | Deploy key or HTTPS token on server for GitHub; or use Workflow B |
| Site breaks after deploy | Run `upgrade_roles.php`; verify `config.php` DB credentials; check Apache/IIS error log |
| Permission errors after hook | `chown` web server user on `/var/www/CLASS` or fix hook user |

---

## Quick reference

```powershell
# Connect
ssh wpu-class

# Deploy via GitHub
git push origin main
ssh wpu-class "cd /var/www/CLASS && git pull origin main"

# Deploy via bare repo
git push production main
```

Replace `wpu-class`, branch name (`main`), and paths with your values.
