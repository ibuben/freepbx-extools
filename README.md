# eXTools (exunity)

Открытый модуль для [FreePBX 17](https://www.freepbx.org/) от [eXUnity LAB](https://ex.uz). Расширяет АТС без патчей Core, Queues, Ring Groups, framework и Voicemail: массовая работа с внутренними номерами, уведомления в Telegram (включая голосовую почту), HTTP-автонастройка телефонов, корпоративная телефонная книга, история звонков, записи разговоров и «липкий» последний оператор очереди.

Open-source [FreePBX 17](https://www.freepbx.org/) module from [eXUnity LAB](https://ex.uz): bulk extensions, Telegram notify (including voicemail), HTTP phone provisioning, Contact Manager phonebooks, call history, recording options, and sticky last agent. It does not patch Core, Queues, Ring Groups, framework, or Voicemail PHP.

Внутри FreePBX имя модуля (rawname) — `exunity`. Лицензия: [GPLv3+](LICENSE).  
Репозиторий: https://github.com/ibuben/freepbx-extools

## Возможности / Features

- **Внутренние номера** — массовое создание и правка экстеншенов (диапазон, SIP-пароли, CID).
- **Telegram** — входящий звонок и пропущенный вызов в чат; голосовая почта уходит тем же чатом (Asterisk `externnotify`, без правки модуля Voicemail).
- **Телефоны** — HTTP-провижининг Yealink, Grandstream, Fanvil, MicroSIP; шаблоны, MAC, переменные.
- **Телефонная книга** — группы Contact Manager и внутренние номера в справочнике аппарата.
- **Исходящие маршруты** — массовые CallerID на Dial Patterns.
- **Очереди и группы вызова** — dual-list выбора агентов; sticky last agent (сначала звонит тот, кто уже говорил с этим абонентом).
- **Записи** — стерео WAV, сжатие в MP3, срок хранения аудио CDR (история звонков не удаляется).
- **Интерфейс** — тёмная тема админки FreePBX, вкладки в eX Settings.

- **Extensions** — bulk create and edit (ranges, SIP secrets, CID).
- **Telegram** — ringing and missed-call messages; voicemail to the same chat via Asterisk `externnotify` (no Voicemail module patch).
- **Phones** — HTTP autoprovision for Yealink, Grandstream, Fanvil, MicroSIP; templates, MAC, variables.
- **Phonebook** — Contact Manager groups plus PBX extensions on the device directory.
- **Outbound routes** — bulk CallerID rows on dial patterns.
- **Queues / ring groups** — dual-list agent picker; sticky last agent.
- **Recordings** — stereo WAV, optional MP3, CDR audio retention (CDR rows are kept).
- **UI** — dark admin theme, tabbed eX Settings.

## Установка / Install

SSH на АТС под root.

### Одна команда (ветка `main`)

```bash
fwconsole ma downloadinstall https://github.com/ibuben/freepbx-extools/archive/refs/heads/main.zip
fwconsole reload
```

Дальше **Admin → Module Admin**: включите **eXTools**, если не включился сам, и Apply Config при запросе.

### Релиз GitHub

```bash
fwconsole ma downloadinstall https://github.com/ibuben/freepbx-extools/releases/latest/download/exunity.tgz
fwconsole reload
```

Та же команда обновляет уже установленный модуль.

### Git clone

```bash
cd /var/www/html/admin/modules
git clone https://github.com/ibuben/freepbx-extools.git exunity
fwconsole ma install exunity
fwconsole chown
fwconsole reload
```

### Загрузка в GUI

1. `bash scripts/pack.sh` → `dist/exunity.tgz`
2. FreePBX → **Admin → Module Admin → Upload modules**
3. Process / enable **eXTools** → Apply Config

### Предупреждение Unsigned

Пока GPG-ключ не подписан Master Key FreePBX, Module Admin показывает **Module is Unsigned**. Для стороннего GPL-модуля это нормально. Не отключайте `SIGNATURECHECK` на боевой АТС.

## После установки

- **Settings → eX Settings** — тема, Telegram, телефоны, книга, записи, очереди
- **Applications** — Bulk Extensions, Bulk Edit, Phones, Phone Templates, Telegram Destinations
- **Reports → eX Call History**

Провижининг отдаётся с `/provision/` в webroot АТС (каталог создаётся при установке).

## Требования / Requirements

- FreePBX 17, framework и Core ≥ 17.0.1
- По желанию: Contact Manager; ffmpeg / sox / lame (голос в Telegram, стерео, MP3)

## Сборка релиза / Release tarball

```bash
bash scripts/pack.sh
```

Тег `v17.0.9` (как в `module.xml`) через GitHub Actions кладёт в Release файлы `exunity.tgz` и `update.json`.

Корень git **и есть** модуль FreePBX. Не оборачивайте его ещё одной папкой — иначе `fwconsole ma downloadinstall` zip с GitHub не найдёт `module.xml`.
