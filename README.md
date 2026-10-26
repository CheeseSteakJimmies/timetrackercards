![Time Tracker Cards](https://github.com/CheeseSteakJimmies/timetrackercards/blob/main/TimerApp.png)

# Time Tracker Cards

Countdown timers for Nextcloud that you can put on cards and share with other
users.

Create as many timers as you like, give each one a name, an optional note and a
length, arrange them as cards on your own board, and invite other users to a
timer. Everybody with access sees the same time left, anyone can start or pause
it, and everybody gets a Nextcloud notification when the time is up.

Typical uses: a child's screen time ("Screen time, morning" / "Screen time,
afternoon"), your own free time or gaming time, food in the oven, a workout
interval, a shared meeting timer.

## About this project

This app was made by "vibe Clauding". The idea, code edits, the design decisions, the testing and all the feedback come from
the author ([@CheeseSteakJimmies](https://github.com/CheeseSteakJimmies)). The code was
written together with an AI assistant, Claude by Anthropic.

The author has **no previous experience with PHP** and is more at home with CSS,
JavaScript and GDScript (game programming). The PHP backend in particular has
therefore not been written or reviewed by an experienced PHP or Nextcloud
developer. It works in the author's own installation, but please treat it with
the care you would give any young project, and review it before you rely on it.
Bug reports, code reviews and pull requests are very welcome and will be reviewed by the author, not an AI.

## Features

- Any number of timers, each with its own name, an optional note of up to
  150 characters (shown under the title) and a length (1 minute to 24 hours)
- Cards on a board, three per row and every row centred (fewer per row on small
  screens). Drag a card by its handle to move it left, right, up or down. Use
  *Move left / Move right* in the card menu on touch screens.
  Every user has their own order.
- Invite other users to a timer. Invited users see it on their own board and can
  start and pause it. The owner can add and remove people, edit and delete it;
  invited users can leave.
- A progress ring around the time shows how much is left. It drains smoothly,
  starts in your Nextcloud accent colour and turns amber and then red. A short
  fading tail follows the pointer.
- All cards are exactly the same size, so the board stays tidy while you drag.
- The app background and the cards use a translucent tint of the accent colour
  chosen in the user's Nextcloud settings. Text and graphics are derived from
  Nextcloud's own theme colours, so contrast stays soft but readable in light
  and dark themes with any accent colour; the ring goes from the accent colour
  to Nextcloud's warning and danger colours.
- Notification via the Nextcloud Notifications app when the time is up: web,
  desktop client and mobile apps (push). If a page is open it also plays a soft,
  low chime.
- A widget for the Nextcloud dashboard (see below)
- The end time is stored on the server, so timers keep running with every
  browser closed and show the same time on every device.
- Available in many languages (see below)

## Dashboard widget

Open the Nextcloud dashboard, choose *Customize* and switch on **Time Tracker
Cards**. The widget lists all your timers in the order of your board: a small
ring, the name, the time left, and a button to start, pause or resume. A daily
allowance that is used up has no button. The title and the link at the bottom
open the full board. The widget updates by itself and keeps counting while the
dashboard is open.

## Resettable or daily

Each timer has a switch, *Allow resetting the time*:

- **On (default):** anyone can reset the timer, and start it again after it has
  finished. Use it for an oven timer, a game session and so on.
- **Off:** a daily allowance. Nobody can reset it by hand. It can be paused and
  resumed, and when it is used up it stays at zero. It cannot be started again
  until the next day, when it starts over with the full time. "Day" follows the
  timezone of the browser that created or last edited the card. Editing the card
  never gives time back; only switching *Allow resetting the time* on does.

## Languages

The app is shown in the language each user has chosen in their own Nextcloud
settings, and notifications are written in the language of the person who
receives them. Names and notes that users type in are shown as typed.

Translations are included for: Arabic (ar), Chinese (zh_CN, zh_TW), Czech (cs),
Danish (da), Dutch (nl), Finnish (fi), French (fr), German (de, and de_DE for the
formal form of address), Italian (it), Japanese (ja), Korean (ko), Norwegian
Bokmål (nb), Polish (pl), Portuguese (pt_BR, pt_PT), Russian (ru), Spanish (es),
Swedish (sv), Turkish (tr) and Ukrainian (uk). In every other language the app
shows English.

Apart from Swedish, the translations were produced with AI and have **not** been
reviewed by native speakers. Corrections are very welcome: edit the matching
file in `l10n/` (for example `l10n/de.json`) and send a pull request. To add a
language, copy `l10n/sv.json`, translate the values, and set `pluralForm` to the
gettext plural rule of that language.

## Requirements

- Nextcloud 32 to 35 (see `appinfo/info.xml`)
- The **Notifications** app enabled (it is by default)
- **Background jobs set to Cron, running every minute** (see below)

## Important: run cron every minute

The notification is sent by a background job. If nobody has the app open when a
timer ends, the notification is sent the next time cron runs. Nextcloud's
default is every 5 minutes, which means the notification can arrive up to
5 minutes late. If a page *is* open, it arrives immediately.

For a notification within about a minute:

1. Set background jobs to Cron:
   `php occ background:cron`
   (or *Administration settings → Basic settings → Background jobs → Cron*)
2. Run cron every minute.

The check itself is a single, cheap database query and the background job may
run as often as cron does. The cost of running cron more often is that
Nextcloud starts for every run (typically 0.1 to 0.3 seconds of processor time).
For a delay of at most 15 seconds, change `sleep 60` to `sleep 15` in the Docker
example below.

### Docker Compose

The official image's `/cron.sh` runs cron every 5 minutes. Replace it with a
loop that runs every minute:

```yaml
  cron:
    image: nextcloud:latest
    restart: unless-stopped
    user: "33:33"
    depends_on:
      - db
      - app
    volumes:
      - nextcloud:/var/www/html
    entrypoint: ["/bin/sh", "-c", "while true; do php -f /var/www/html/cron.php; sleep 60; done"]
```

Adjust the service names (`db`, `app`) and the volume to match your stack.
`cron:` must be indented so that it sits under `services:`.

### System cron (non-Docker)

```
* * * * * php -f /var/www/nextcloud/cron.php
```

Run it as the web server user (for example `www-data`).

## Installation (manual)

1. Copy the `timetrackercards` folder to your Nextcloud's `custom_apps`
   directory and make sure the web server user owns it.
2. Enable it: `php occ app:enable timetrackercards`
   This also creates the database tables.

With Docker:

```bash
docker cp timetrackercards nextcloud-app-1:/var/www/html/custom_apps/
docker exec nextcloud-app-1 chown -R www-data:www-data /var/www/html/custom_apps/timetrackercards
docker exec -u www-data nextcloud-app-1 php occ app:enable timetrackercards
```

When you update an installed copy, run `php occ upgrade` afterwards.

If Nextcloud reports the app as incompatible, run `php occ status` and adjust
`min-version` / `max-version` in `appinfo/info.xml`.

## Usage

1. Open **Time Tracker Cards** in the app menu and press **New timer**.
2. Enter a name, an optional note and a length, choose whether the time may be
   reset, and create.
3. Open the card menu (three dots) and choose **Share…** to invite someone.
4. Press **Start**. Anyone with access can pause, resume or (if allowed) reset.

**Each person who should get the notification must be a member of the timer.**
Invited users see the timer on their board after their next refresh (at most a
few seconds).

For notifications on a phone, install the official Nextcloud mobile app and log
in. Push notifications need the server to be reachable over HTTPS.

### Who can be invited

Suggestions come from Nextcloud's own user search, so the administrator's
sharing settings apply (user autocompletion, restrictions to groups). Invitations
to users that the search does not return are rejected.

## Data

The app creates two tables, `timetrackercards_timers` and
`timetrackercards_members`. They hold the timer name, note, length and state,
the owner, the list of invited users and the position of the card on each
user's board. When a user account is deleted, their timers are deleted and they
are removed from timers shared with them.

## Troubleshooting

- **No notification when the page is closed:** check that background jobs use
  Cron and that cron really runs every minute (*Administration settings →
  Basic settings* shows the last run).
- **No notification on the phone:** the browser cannot receive notifications
  when it is closed. Use the Nextcloud mobile app and allow notifications for
  it in the phone's settings. Test with
  `php occ notification:test-push <user>`.
- **No notification at all:** make sure the Notifications app is enabled
  (`php occ app:list | grep notifications`).
- **The page looks broken after an update:** the browser or a CDN is serving old
  CSS or JavaScript. The app version in `info.xml` is part of the file URLs, so
  raise it on every change, and do a hard refresh (Ctrl+Shift+R).
- **App will not enable:** check the Nextcloud version against `info.xml`.

## Development notes

- Backend: PHP, timer state in the database (`TimerService`, `TimerMapper`),
  schema in `lib/Migration`
- Frontend: plain JavaScript and CSS, no build step
- Background job: `OCA\TimeTrackerCards\Cron\CheckTimerJob` (every 60 seconds)
- The API lives under `/apps/timetrackercards/api/…` (see `appinfo/routes.php`)

## License

AGPL-3.0, meaning:
- Everyone may use, modify, share and sell the app, even commercially.
- If any further app provides it to AGPL, the source code must be included or available, license text and copyright notices must remain in place, and changes must be stated.
- If someone changes the app and runs it as a service for other users, such as on a shared Nextcloud server, they must offer their changed source code to those users. That's the part that distinguishes AGPL from regular GPL.
- No one can make a closed, proprietary copy of the code. Branches must also be AGPL.
- No guarantees, so you and I are not responsible if something goes wrong.
