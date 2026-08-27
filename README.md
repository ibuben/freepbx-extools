[English](README.md) · [Русский](README.ru.md)

# eXTools (**eXtended Tools for FreePBX by eXUnity LAB**)

An open multifunctional module for [FreePBX 17](https://www.freepbx.org/) from [eXUnity LAB](https://exunity.uz). It brings a mid-size business PBX feature set without touching core code, the original framework, or other modules. Installing eXTools does not break the integrity of a FreePBX install and does not conflict with later FreePBX updates.

Inside FreePBX the module rawname is `exunity`. License: [GPLv3+](LICENSE).  
Repository: https://github.com/ibuben/freepbx-extools

## Features

- Bulk extension operations: create by range, bulk-edit selected extension parameters (including bulk password changes)
- **Telegram** — incoming-call alerts through your Telegram bot. A separate Chat ID can be attached to each extension.
- **Phone autoprovisioning** — HTTP provisioning, used together with DHCP Option 66 (configure that on your DHCP server). Supports Yealink, Grandstream, Fanvil, and MicroSIP* (* requires a special MicroSIP build that can fetch settings).
- **Phonebook** — Contact Manager groups and PBX extensions in the phone directory.
- **Outbound routes** — convenient bulk CallerID rows on Dial Patterns.
- **Queues and ring groups** — convenient bulk agent selection
- Sticky last agent (sends the caller back to the agent they already spoke with)
- **Recordings** — per-channel stereo WAV (often used with AI), MP3 compression, automatic cleanup of old audio after the retention period
- **UI** — a dark admin theme that is easier on the operator’s eyes
- **Alternative CDR view** — a simpler, cleaner interface to browse and listen to call recordings. Supports faster playback. Hides queue-call tails so you only see the information you need.

## Install

* Run as root.

### `main` branch

```bash
fwconsole ma downloadinstall https://github.com/ibuben/freepbx-extools/archive/refs/heads/main.zip
fwconsole reload
```

Then go to **Admin → Module Admin**, enable **eXTools** if it did not enable itself, and Apply Config when asked.

### GitHub Release

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

FreePBX will report that this module is unsigned. That is expected. Ignore the notice. Do not turn off `SIGNATURECHECK` on a production PBX.

## After install

- **Settings → eX Settings** — theme, Telegram, phones, phonebook, recordings, queues
- **Applications** — Bulk Extensions, Bulk Edit, Phones, Phone Templates, Telegram Destinations
- **Reports → eX Call History**

Provisioning is served from `/provision/` in the PBX webroot (the directory is created on install).

## Requirements

- FreePBX 17, framework and Core ≥ 17.0.1
- Optional: Contact Manager; ffmpeg / sox / lame (Telegram voice, stereo, MP3)
