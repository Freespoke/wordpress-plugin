#!/usr/bin/env bash
# Seed the local WordPress with authors, posts, and a search page that embeds
# the Freespoke widget, so author links can be exercised in a browser.
#
#   ./seed.sh                 # install WordPress and seed the fixtures
#   SEED_BULK_USERS=2000 ./seed.sh   # also add a large user table for timing
#
# Idempotent: re-running updates the fixtures in place.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

SITE_URL="http://localhost:8080"
BASE_URL="${FREESPOKE_BASE_URL:-http://localhost:8890}"
CLIENT_ID="${FREESPOKE_CLIENT_ID:-dev-client}"
BULK_USERS="${SEED_BULK_USERS:-0}"

wp() {
    docker compose run --rm --no-deps -T wpcli wp "$@"
}

echo "Waiting for WordPress to answer..."
for _ in $(seq 1 60); do
    if curl -fsS -o /dev/null "$SITE_URL/wp-login.php"; then
        break
    fi
    sleep 2
done

if ! wp core is-installed >/dev/null 2>&1; then
    echo "Installing WordPress..."
    wp core install \
        --url="$SITE_URL" \
        --title="Freespoke Plugin Dev" \
        --admin_user=admin \
        --admin_password=admin \
        --admin_email=admin@example.test \
        --skip-email
fi

wp option update permalink_structure '/%year%/%monthnum%/%day%/%postname%/' >/dev/null
wp option update blogdescription 'Local WordPress for the Freespoke Search plugin' >/dev/null
wp rewrite flush --hard >/dev/null

# ---------------------------------------------------------------------------
# Authors. Display names cover the matching rules: a middle name the visitor
# will not type, punctuated initials, an apostrophe, and a shared surname.
# Two have pictures via the dev avatar filter; the rest fall through to a
# Gravatar that does not exist, which is the "no picture" path.
# ---------------------------------------------------------------------------
ensure_author() {
    local login="$1" display="$2" avatar="${3:-}" title="${4:-}" description="${5:-}"
    local first last
    first="${display%% *}"
    last="${display##* }"
    if ! wp user get "$login" --field=ID >/dev/null 2>&1; then
        wp user create "$login" "$login@example.test" \
            --role=author --display_name="$display" \
            --first_name="$first" --last_name="$last" \
            --user_pass=password >/dev/null
    else
        wp user update "$login" --display_name="$display" >/dev/null
    fi
    set_meta "$login" freespoke_dev_avatar "$avatar"
    set_meta "$login" freespoke_dev_title "$title"
    set_meta "$login" freespoke_dev_description "$description"
}

# The dev mu-plugin reads these through the freespoke_search_author filter,
# standing in for the user fields a publisher's theme already keeps.
set_meta() {
    local login="$1" key="$2" value="$3"
    if [ -n "$value" ]; then
        wp user meta update "$login" "$key" "$value" >/dev/null
    else
        wp user meta delete "$login" "$key" >/dev/null 2>&1 || true
    fi
}

ensure_post() {
    local login="$1" title="$2"
    local author_id existing
    author_id="$(wp user get "$login" --field=ID)"
    existing="$(wp post list --post_type=post --title="$title" --field=ID --posts_per_page=1)"
    if [ -z "$existing" ]; then
        wp post create --post_type=post --post_status=publish \
            --post_author="$author_id" --post_title="$title" \
            --post_content="<p>Placeholder body for \"$title\".</p>" >/dev/null
    fi
}

ensure_author elizabeth.mitchell "Elizabeth Troutman Mitchell" "https://static.dailysignalmedia.org/MitchellElizabeth-150x150.jpg" \
    "White House Correspondent" "Elizabeth Troutman Mitchell is the White House correspondent for the Daily Signal."
ensure_author jd.foster           "J.D. Foster"                 "$BASE_URL/avatars/jd.svg" "Senior Fellow"
ensure_author sean.oconnor        "Sean O'Connor"
ensure_author dan.mitchell        "Dan Mitchell"                "" "Contributor" "Dan Mitchell writes about tax &amp; fiscal policy."
ensure_author amy.mitchell        "Amy Mitchell"                "$BASE_URL/avatars/elizabeth.svg"
ensure_author bo.mitchell         "Bo Mitchell"
# Has the surname but no posts, so the lookup must skip them.
ensure_author ghost.mitchell      "Ghost Mitchell"

ensure_post elizabeth.mitchell "Vance Wants Senate to Pass Anti-Fraud Legislation"
ensure_post elizabeth.mitchell "Inside Vance's First-Ever Florida Rally"
ensure_post jd.foster           "A Column With Initials in the Byline"
ensure_post sean.oconnor        "An Apostrophe in the Byline"
ensure_post dan.mitchell        "First of the Mitchells"
ensure_post amy.mitchell        "Second of the Mitchells"
ensure_post bo.mitchell         "Third of the Mitchells"

# ---------------------------------------------------------------------------
# Optional: authors as a post type. Only when FREESPOKE_AUTHOR_POST_TYPE is set
# on the containers (the dev mu-plugin registers the type). Each author post
# carries the public name as title, the one-line description as excerpt, the
# bio as content, a job title in post meta, and the headshot as featured
# image. The user accounts above keep the bylines; the plugin matches on the
# post titles instead, which can be fuller than the display names.
# ---------------------------------------------------------------------------
AUTHOR_POST_TYPE="${FREESPOKE_AUTHOR_POST_TYPE:-}"

# Title, description, bio and the link to the user are custom fields on the
# author post (meta keys title, summary, bio, user_login_temporary), matching
# what the dev mu-plugin maps through freespoke_search_author_post_meta. The
# description and bio are stored as HTML, as WYSIWYG fields would be, and the
# user by login. The excerpt is left empty so the mapping, not the default, is
# what gets exercised.
ensure_author_post() {
    local slug="$1" title="$2" user_login="$3" description="${4:-}" job_title="${5:-}" image="${6:-}"
    local existing post_id user_id
    existing="$(wp post list --post_type="$AUTHOR_POST_TYPE" --name="$slug" --field=ID --posts_per_page=1 --post_status=any)"
    if [ -z "$existing" ]; then
        post_id="$(wp post create --post_type="$AUTHOR_POST_TYPE" --post_status=publish --porcelain \
            --post_name="$slug" --post_title="$title" \
            --post_content="<p>$title is a writer at this site. This paragraph stands in for the full biography.</p>")"
    else
        post_id="$existing"
        wp post update "$post_id" --post_title="$title" --post_excerpt="" >/dev/null
    fi
    wp post meta update "$post_id" user_login_temporary "$user_login" >/dev/null
    set_post_meta "$post_id" title "$job_title"
    set_post_meta "$post_id" summary "${description:+<p>$description</p>}"
    set_post_meta "$post_id" bio "<p>$title is a writer at this site.</p>"
    if [ -n "$image" ] && [ -z "$(wp post meta get "$post_id" _thumbnail_id 2>/dev/null)" ]; then
        wp media import "$image" --post_id="$post_id" --featured_image --porcelain >/dev/null
    fi
}

set_post_meta() {
    local post_id="$1" key="$2" value="$3"
    if [ -n "$value" ]; then
        wp post meta update "$post_id" "$key" "$value" >/dev/null
    else
        wp post meta delete "$post_id" "$key" >/dev/null 2>&1 || true
    fi
}

if [ -n "$AUTHOR_POST_TYPE" ]; then
    echo "Seeding author posts (post type: $AUTHOR_POST_TYPE)..."
    ensure_author_post elizabeth-mitchell "Elizabeth Troutman Mitchell" elizabeth.mitchell \
        "Elizabeth Troutman Mitchell is the White House correspondent for the Daily Signal." \
        "White House Correspondent" "https://static.dailysignalmedia.org/MitchellElizabeth-150x150.jpg"
    ensure_author_post jd-foster    "J.D. Foster"   jd.foster    "" "Senior Fellow"
    ensure_author_post sean-oconnor "Sean O'Connor" sean.oconnor
    ensure_author_post dan-mitchell "Dan Mitchell"  dan.mitchell "Dan Mitchell writes about tax &amp; fiscal policy." "Contributor"
    ensure_author_post amy-mitchell "Amy Mitchell"  amy.mitchell
    ensure_author_post bo-mitchell  "Bo Mitchell"   bo.mitchell
    wp rewrite flush --hard >/dev/null
fi

# ---------------------------------------------------------------------------
# The search page: a redirect-only widget as a site header would have, and
# the embedded widget that auto-searches the q parameter. Mirrors how The
# Daily Signal's search page is put together.
# ---------------------------------------------------------------------------
read -r -d '' PAGE_CONTENT <<EOF || true
<!-- wp:heading --><h2>Header search (redirect only)</h2><!-- /wp:heading -->
<!-- wp:shortcode -->
[freespoke_search client_id="$CLIENT_ID" theme="light" placeholder="Search this site…" redirect_url="/search/?q={query}" auto_search="true"]
<!-- /wp:shortcode -->
<!-- wp:heading --><h2>Results</h2><!-- /wp:heading -->
<!-- wp:shortcode -->
[freespoke_search client_id="$CLIENT_ID" theme="light" placeholder="Search this site…" redirect_url="/search/?q={query}" embedded_search="true" auto_search="true" min_height="400px"]
<!-- /wp:shortcode -->
EOF

# The Daily Signal's search page runs the widget nearly edge to edge inside a
# 1460px row, far wider than this theme's content column. A full-width group
# reproduces that so the author grid can be checked at real desktop widths.
read -r -d '' WIDE_CONTENT <<EOF || true
<!-- wp:group {"align":"full","layout":{"type":"constrained","contentSize":"1460px","wideSize":"1460px"}} -->
<div class="wp-block-group alignfull">
<!-- wp:shortcode -->
[freespoke_search client_id="$CLIENT_ID" theme="light" placeholder="Search this site…" redirect_url="/search-wide/?q={query}" embedded_search="true" auto_search="true" min_height="400px"]
<!-- /wp:shortcode -->
</div>
<!-- /wp:group -->
EOF

read -r -d '' OPT_OUT_CONTENT <<EOF || true
<!-- wp:paragraph --><p>Same page with <code>author_links="false"</code>; the author row must not appear here.</p><!-- /wp:paragraph -->
<!-- wp:shortcode -->
[freespoke_search client_id="$CLIENT_ID" theme="light" redirect_url="/search-no-authors/?q={query}" embedded_search="true" auto_search="true" author_links="false" min_height="400px"]
<!-- /wp:shortcode -->
EOF

ensure_page() {
    local slug="$1" title="$2" content="$3"
    local existing
    existing="$(wp post list --post_type=page --name="$slug" --field=ID --posts_per_page=1)"
    if [ -z "$existing" ]; then
        wp post create --post_type=page --post_status=publish \
            --post_name="$slug" --post_title="$title" --post_content="$content" >/dev/null
    else
        wp post update "$existing" --post_content="$content" >/dev/null
    fi
}

ensure_page search "Search" "$PAGE_CONTENT"
ensure_page search-wide "Search (full width, like The Daily Signal)" "$WIDE_CONTENT"
ensure_page search-no-authors "Search (author links off)" "$OPT_OUT_CONTENT"

if [ "$BULK_USERS" -gt 0 ]; then
    echo "Generating $BULK_USERS extra users for lookup timing..."
    wp user generate --count="$BULK_USERS" --role=subscriber >/dev/null
fi

echo
echo "Ready."
echo "  Admin:            $SITE_URL/wp-admin/  (admin / admin)"
echo "  One match:        $SITE_URL/search/?q=elizabeth+mitchell"
echo "  Initials:         $SITE_URL/search/?q=jd+foster"
echo "  Apostrophe:       $SITE_URL/search/?q=sean+o%27connor"
echo "  Capped at three:  $SITE_URL/search/?q=mitchell"
echo "  Full width:       $SITE_URL/search-wide/?q=mitchell"
echo "  No author:        $SITE_URL/search/?q=tax+policy"
echo "  Opt-out:          $SITE_URL/search-no-authors/?q=elizabeth+mitchell"
