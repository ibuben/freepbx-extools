[English](README.md) · [Русский](README.ru.md)
![eXTools](/exlogo.png)
# eXTools (**eXtended Tools for FreePBX by eXUnity LAB**)

Открытый мультифункциональный модуль для [FreePBX 17](https://www.freepbx.org/) от [eXUnity LAB](https://exunity.uz). Расширяет возможности АТС до уровня средних корпоративных решений, не затрагивая код ядра, оригинального фреймворка и других модулей. Установка данного модуля не нарушает целостность инсталляции FreePBX и не конфликтует с её последующими обновлениями.

Внутри FreePBX имя модуля (rawname) — `exunity`. Лицензия: [GPLv3+](LICENSE).  
Репозиторий: https://github.com/ibuben/freepbx-extools

## Возможности

- массовые операции с экстеншанами (внутренними номерами): Создание по диапазону, массовое редактирование некоторых параметров экстеншанов (включая массовое изменение паролей)
- **Telegram** — Оповещения о входящих вызовах через вашего Telegram бота. Можно прикреплять отдельные ChatID к каждому экстеншану.
- **Автонастройка телефонов** — HTTP-провижининг, работает в комбинации с Option 66 (требуется настройка на вашем DHCP сервере) с поддержкой Yealink, Grandstream, Fanvil, MicroSIP* (* Требуется специальная сборка MicroSIP с поддержкой получения настроек).
- **Телефонная книга** — группы Contact Manager и внутренние номера в справочнике аппарата.
- **Исходящие маршруты** — Удобное массовое добавление CallerID на Dial Patterns.
- **Очереди и группы вызова** — Удобный массовый выбора агентов;
- Sticky last agent (Возвращает звонящего к оператору, с которым он ранее разговаривал.).
- **Записи** — Поканальное стерео WAV (Часто используется для работы с ИИ), сжатие в MP3, автоочистка старых аудио записей по истечению срока хранения.
- **Интерфейс** — тёмная тема панели управления бережёт глаза админа.
- **Альтернативное отображение CDR** - Упрощённый и более приятный глазу интерфейс для просмотра и прослушки записей звонков. Поддерживает ускоренное воспроизведение. Убирает из списка хвосты звонков в очередь: Вы видите только нужную информацию.

## Установка

* Выполняется под root.

### ветка `main`

```bash
fwconsole ma downloadinstall https://github.com/ibuben/freepbx-extools/archive/refs/heads/main.zip
fwconsole reload
```

Далее, перейдите в **Admin → Module Admin**: включите **eXTools**, если не включился сам, и Apply Config при запросе.

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

Система будет сообщать о том, что данный модуль не подписан, это нормально. Просто игнорируйте сообщение. Не отключайте `SIGNATURECHECK` на боевой АТС.

## После установки

- **Settings → eX Settings** — тема, Telegram, телефоны, книга, записи, очереди
- **Applications** — Bulk Extensions, Bulk Edit, Phones, Phone Templates, Telegram Destinations
- **Reports → eX Call History**

Провижининг отдаётся с `/provision/` в webroot АТС (каталог создаётся при установке).

## Требования

- FreePBX 17, framework и Core ≥ 17.0.1
- По желанию: Contact Manager; ffmpeg / sox / lame (голос в Telegram, стерео, MP3)
