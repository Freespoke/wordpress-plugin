# Local WordPress for plugin development

A WordPress site in Docker that runs this plugin from source, against a widget
bundle built from this checkout, with authors and posts seeded for the author
link feature. Nothing here talks to a Freespoke environment unless you point it
at one.

## What runs

| Service     | URL                    | Purpose |
| ----------- | ---------------------- | ------- |
| `wordpress` | http://localhost:8080  | WordPress with the plugin source mounted and booted by a dev mu-plugin |
| `mock-api`  | http://localhost:8890  | Serves the widget bundle from `app/web/public/widgets` plus canned theme and search responses |
| `db`        | internal               | MariaDB |
| `wpcli`     | on demand              | WP-CLI, used by `seed.sh` |

The plugin is mounted at `wp-content/plugins/freespoke-search` but left
inactive. `mu-plugins/freespoke-dev.php` boots it instead, passing
`FREESPOKE_BASE_URL` in place of the `__BASE_URL__` placeholder that
`build.sh` stamps at release time. The same mu-plugin answers
`get_avatar_url()` for users carrying `freespoke_dev_avatar` meta, standing in
for the avatar plugins publishers use for staff photos.

## First run

```bash
# 1. Plugin dev dependencies (the mu-plugin requires vendor/autoload.php)
(cd .. && composer install)

# 2. Widget bundle, pointed at the mock API. The build also needs the Matomo
#    settings (NEXT_PUBLIC_WIDGET_MATOMO_SITE_ID and the two NEXT_PUBLIC_MATOMO_*
#    URLs), which it reads from app/web/.env.
(cd ../../../../web && NEXT_PUBLIC_BASE_URL=http://localhost:8890 pnpm run build:widget)

# 3. Containers and fixtures
docker compose up -d
./seed.sh
```

`seed.sh` prints the URLs to visit. Admin login is `admin` / `admin`.

## Seeded fixtures

| Author                       | Posts | Picture | Title / description | Exercises |
| ---------------------------- | ----- | ------- | ------------------- | --------- |
| Elizabeth Troutman Mitchell  | 2     | real Daily Signal headshot | both | long name, every field filled, ordered first by post count |
| J.D. Foster                  | 1     | yes     | title only | punctuated initials, matched by `jd foster` and `j. d. foster` |
| Sean O'Connor                | 1     | no      | none | apostrophe in the name; name-only card |
| Dan Mitchell                 | 1     | no      | both | title and description without a picture |
| Amy Mitchell                 | 1     | yes     | none | picture without title or description |
| Bo Mitchell                  | 1     | no      | none | fourth `mitchell` match, cut by the cap of three |
| Ghost Mitchell               | 0     | no      | none | has the surname but no published posts, so is skipped |

Title, description, and pictures reach the plugin through the
`freespoke_search_author` filter, which the dev mu-plugin implements from
`freespoke_dev_*` user meta, the way a publisher would implement it from its
own author fields.

Two pages: `/search/` with a redirect-only widget and an embedded one, like
The Daily Signal's layout, and `/search-no-authors/` with
`author_links="false"`.

## Variations

**Authors as a post type.** Some sites keep authors as posts (an "Authors"
section in the admin, each with a featured-image headshot, an excerpt, and a
bio), with the user accounts only carrying the bylines. Setting the post type
name on the containers makes the mu-plugin register one, moves the native
author archive to `/writer/` so the posts can live at `/author/<slug>/`, and
has the seed create an author post per writer with the headshot as featured
image and the job title, description, bio, and the link to the user as
custom fields (`title`, `summary`, `bio`, `user_login_temporary`, named after
a real ACF field group: the description and bio hold HTML as WYSIWYG fields
do, and the user is linked by login), which the mu-plugin maps through
`freespoke_search_author_post_meta`. The plugin then
matches on the post titles, so `/search/?q=troutman` finds Elizabeth even
though her display name is shorter, and orders a shared surname by the linked
users' post counts:

```bash
FREESPOKE_AUTHOR_POST_TYPE=post-author docker compose up -d
FREESPOKE_AUTHOR_POST_TYPE=post-author ./seed.sh
```

Run both commands without the variable to go back to matching on users. The
author posts stay in the database but are ignored.

**Without mbstring.** WordPress recommends the extension but does not require
it, and the resolver must work without it. The official image compiles
mbstring in, so the overlay disables the functions instead, which is what the
code sees on a host that lacks the extension:

```bash
docker compose -f compose.yaml -f compose.no-mbstring.yaml up -d wordpress
docker compose exec wordpress php -r 'var_dump(function_exists("mb_strtolower"));'   # bool(false)
```

Run `docker compose up -d wordpress` without the overlay to put it back.

**Against a real Freespoke environment.** Rebuild the bundle for that base URL,
set `FREESPOKE_BASE_URL` and `FREESPOKE_CLIENT_ID` to a widget client whose
allowed domains include `http://localhost:8080`, then re-run `docker compose
up -d` and `./seed.sh`:

```bash
FREESPOKE_BASE_URL=https://staging.freespoke.com FREESPOKE_CLIENT_ID=<client> docker compose up -d
FREESPOKE_BASE_URL=https://staging.freespoke.com FREESPOKE_CLIENT_ID=<client> ./seed.sh
```

**Lookup timing on a large user table.** `SEED_BULK_USERS=2000 ./seed.sh`
adds that many subscribers. The query log is at
`wp-content/debug.log` inside the `wordpress` container.

## Reset

```bash
docker compose down -v
```
