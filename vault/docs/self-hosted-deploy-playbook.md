# STS Deployment Playbook — Self-Hosted GitHub Actions Runner

## Purpose

This document records how the `sts-ticketing-demo` project deploys to production and how the self-hosted GitHub Actions runner was set up on `debian-hserver`.

Use this as the future reference for:

- production deployment explanations
- reproducing the setup on another repo
- course/tutorial material

## Production target

- **Public URL:** `https://sts-demo.betamaxgroup.tech`
- **Application host:** `debian-hserver`
- **Local app directory on server:** `$HOME/projects/sts-ticketing`
- **Ingress path:** Cloudflare → betamax-mark → WireGuard gateway → `debian-hserver` → Caddy → STS container (`127.0.0.1:8080`)

## Why SSH-based deploy was replaced

The original GitHub Actions deploy job used `appleboy/ssh-action` with these secrets:

- `DEPLOY_HOST`
- `DEPLOY_USER`
- `DEPLOY_SSH_KEY`

That failed because the actual app host is not directly reachable from GitHub-hosted runners:

- LAN IP is private
- WireGuard IP is only reachable by VPN peers
- no public SSH route was exposed for the STS host

Instead of exposing SSH publicly, the deployment was moved to a **self-hosted runner running on the destination server itself**.

## Final deployment model

```text
git push origin main
  -> GitHub Actions "test" job on ubuntu-latest
  -> if green, GitHub Actions "deploy" job on self-hosted runner [self-hosted, sts]
  -> runner executes directly on debian-hserver
  -> repo reset to origin/main
  -> docker compose build/down/up
  -> site updated
```

## Self-hosted runner details

- **Repo:** `mark-cervantes/sts-ticketing-demo`
- **Runner directory:** `~/actions-runner-sts`
- **Runner name:** `debian-hserver-sts`
- **Labels:** `self-hosted, linux, x64, sts`
- **Systemd service:**
  `actions.runner.mark-cervantes-sts-ticketing-demo.debian-hserver-sts.service`

## Runner setup steps used

### 1. Get a registration token

```bash
gh api -X POST repos/mark-cervantes/sts-ticketing-demo/actions/runners/registration-token --jq '.token'
```

### 2. Create a dedicated runner directory on the server

```bash
mkdir -p ~/actions-runner-sts
cd ~/actions-runner-sts
```

### 3. Download the latest GitHub runner

```bash
RUNNER_VERSION=$(curl -s https://api.github.com/repos/actions/runner/releases/latest \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['tag_name'].lstrip('v'))")

curl -sL "https://github.com/actions/runner/releases/download/v${RUNNER_VERSION}/actions-runner-linux-x64-${RUNNER_VERSION}.tar.gz" -o runner.tar.gz
tar xzf runner.tar.gz
rm runner.tar.gz
```

### 4. Register it to the repo

```bash
./config.sh \
  --url https://github.com/mark-cervantes/sts-ticketing-demo \
  --token "<REGISTRATION_TOKEN>" \
  --name "debian-hserver-sts" \
  --labels "self-hosted,linux,x64,sts" \
  --unattended \
  --replace
```

### 5. Install and start the service

```bash
sudo ./svc.sh install cmark
sudo ./svc.sh start
sudo ./svc.sh status
```

## CI workflow change

The deploy job in `.github/workflows/ci.yml` was changed from SSH-action deployment to direct self-hosted execution.

### Before

```yaml
deploy:
  runs-on: ubuntu-latest
  steps:
    - uses: appleboy/ssh-action@v1
```

### After

```yaml
deploy:
  runs-on: [self-hosted, sts]
  steps:
    - name: Deploy to production
      run: |
        set -e
        APP_DIR="$HOME/projects/sts-ticketing"
        if [ ! -d "$APP_DIR" ]; then
          git clone git@github.com:mark-cervantes/sts-ticketing-demo.git "$APP_DIR"
        fi
        cd "$APP_DIR"
        git fetch origin main
        git checkout main
        git reset --hard origin/main
        if [ ! -f .env ]; then
          cp .env.production.example .env
          echo "First deploy: edit .env and rerun"
          exit 1
        fi
        docker compose -f docker-compose.prod.yml build --no-cache
        docker compose -f docker-compose.prod.yml down
        docker compose -f docker-compose.prod.yml up -d
        sleep 10
        docker compose -f docker-compose.prod.yml ps
        docker image prune -f
```

## Verification commands

### Check latest GitHub Actions run

```bash
gh run list --limit 5
gh run view <run-id>
gh run watch <run-id>
```

### Check runner health on the server

```bash
ssh ban-hserver 'systemctl status actions.runner.mark-cervantes-sts-ticketing-demo.debian-hserver-sts'
```

### Restart runner if needed

```bash
ssh ban-hserver 'sudo systemctl restart actions.runner.mark-cervantes-sts-ticketing-demo.debian-hserver-sts'
```

### Verify production site is live

```bash
curl -I https://sts-demo.betamaxgroup.tech
```

Expected: `HTTP/2 200`

## Important operational notes

1. **No deploy SSH secrets are required anymore** for this repo.
2. The runner is **repo-scoped**, not shared globally.
3. The runner executes jobs **on the actual production server**, so workflow commands must be written carefully.
4. `docker compose -f docker-compose.prod.yml build --no-cache` is slower but guarantees fresh images.
5. The first deploy still requires a valid production `.env` file to exist on the server.

## Course / teaching angle

This setup is a good example of when to prefer a self-hosted runner over SSH-based deploys:

- the target host is not publicly SSH-accessible
- you want to avoid storing SSH deploy secrets in GitHub
- the server can safely make outbound HTTPS connections to GitHub
- the deploy process is local shell work anyway (git pull + docker compose)

## Last verified state

- test job: ✅
- deploy job: ✅
- production URL: ✅ `https://sts-demo.betamaxgroup.tech`
