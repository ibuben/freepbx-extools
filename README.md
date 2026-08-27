# eXTools (exunity)

Open-source [FreePBX 17](https://www.freepbx.org/) module from [eXUnity LAB](https://ex.uz): bulk extensions, Telegram notify (including voicemail), HTTP phone provisioning (Yealink, Grandstream, Fanvil, MicroSIP), Contact Manager phonebooks, call history, recording options, and sticky last agent.

Internal module name (rawname) is `exunity`. License: [GPLv3+](LICENSE).

Source: https://github.com/ibuben/freepbx-extools

## Install on FreePBX 17

SSH to the PBX as root.

### One line (current `main`)

```bash
fwconsole ma downloadinstall https://github.com/ibuben/freepbx-extools/archive/refs/heads/main.zip
fwconsole reload
```

Then **Admin → Module Admin**: enable **eXTools** if needed, and apply config if FreePBX asks.

### From a GitHub Release

After a release tagged `v17.0.9` (or newer) exists:

```bash
fwconsole ma downloadinstall https://github.com/ibuben/freepbx-extools/releases/latest/download/exunity.tgz
fwconsole reload
```

The same command upgrades an existing install.

### Git clone

```bash
cd /var/www/html/admin/modules
git clone https://github.com/ibuben/freepbx-extools.git exunity
fwconsole ma install exunity
fwconsole chown
fwconsole reload
```

### Upload in the GUI

1. `bash scripts/pack.sh` → `dist/exunity.tgz`
2. FreePBX → **Admin → Module Admin → Upload modules**
3. Process / enable **eXTools** → Apply Config

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
```

Publishing a GitHub Release tagged `v17.0.9` (matching `module.xml`) attaches `exunity.tgz` and `update.json` via `.github/workflows/release.yml`.

## Requirements

- FreePBX 17 / framework and Core ≥ 17.0.1
- Optional: Contact Manager, ffmpeg / sox / lame (voicemail Opus, stereo, MP3)

This module does not patch Core, Queues, Ring Groups, framework, or Voicemail PHP.

## Development

The git repository **is** the FreePBX module (this directory). Do not wrap it in an extra parent folder, or `fwconsole ma downloadinstall` of a GitHub zip will fail.
