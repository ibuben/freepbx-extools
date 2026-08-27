# eXTools (exunity)

Open-source [FreePBX 17](https://www.freepbx.org/) module: bulk extensions, Telegram notify (including voicemail), HTTP phone provisioning (Yealink, Grandstream, Fanvil, MicroSIP), Contact Manager phonebooks, call history, recording options, and sticky last agent.

Internal module name (rawname) is `exunity`. License: [GPLv3+](LICENSE).

## Install on FreePBX 17

SSH to the PBX as root. Pick one method.

### 1. From a GitHub Release (recommended)

After the first public release:

```bash
fwconsole ma downloadinstall https://github.com/OWNER/REPO/releases/latest/download/exunity.tgz
fwconsole reload
```

Replace `OWNER/REPO` with the GitHub repository. Then **Admin → Module Admin**: enable **eXTools** if it is not already enabled, and apply config if FreePBX asks.

Upgrade later:

```bash
fwconsole ma downloadinstall https://github.com/OWNER/REPO/releases/latest/download/exunity.tgz
fwconsole reload
```

### 2. From git (current `main`)

Works with GitHub’s zip of the repo root (`module.xml` at the top of that zip folder):

```bash
fwconsole ma downloadinstall https://github.com/OWNER/REPO/archive/refs/heads/main.zip
fwconsole reload
```

Or clone into the modules directory:

```bash
cd /var/www/html/admin/modules
git clone https://github.com/OWNER/REPO.git exunity
fwconsole ma install exunity
fwconsole chown
fwconsole reload
```

### 3. Upload in the GUI

1. On a machine with this source: `bash scripts/pack.sh`
2. Take `dist/exunity.tgz`
3. FreePBX → **Admin → Module Admin → Upload modules**
4. Upload the tarball, then **Process** / enable **eXTools**
5. Apply Config

### Unsigned warning

Until a GPG key is signed by the FreePBX Master Key, Module Admin shows **Module is Unsigned**. That is expected for a third-party GPL module. Do not turn off `SIGNATURECHECK` on production systems.

## After install

- **Settings → eX Settings** — theme, Telegram, phones, phonebook, recordings, queues
- **Applications** — Bulk Extensions, Bulk Edit, Phones, Phone Templates, Telegram Destinations
- **Reports → eX Call History**

Provisioning URL is served from `/provision/` on the PBX webroot (created on install).

## Build a release tarball

```bash
bash scripts/pack.sh
# dist/exunity.tgz
# dist/exunity-VERSION.tgz
# dist/update.json   (download URL filled when GITHUB_REPOSITORY is set)
```

Publishing a GitHub Release tagged `v17.0.9` (matching `module.xml`) runs `.github/workflows/release.yml` and attaches `exunity.tgz` plus `update.json`.

Optional third-party updates in Module Admin: set this in `module.xml` (HTTPS only) and ship it in the next tarball:

```xml
<updateurl>https://github.com/OWNER/REPO/releases/latest/download/update.json</updateurl>
```

## Requirements

- FreePBX 17 / framework and Core ≥ 17.0.1
- Asterisk 22 on the distro this module was built against (other 17.x stacks may work)
- Optional: Contact Manager (named phonebook groups), ffmpeg / sox / lame (voicemail Opus, stereo, MP3)

This module does not patch Core, Queues, Ring Groups, framework, or Voicemail PHP.

## Development

The git repository **is** the FreePBX module (this directory). Do not wrap it in an extra parent folder, or `fwconsole ma downloadinstall` of a GitHub zip will fail.
