#!/bin/bash
set -eo pipefail

if [ ! -f /workspace/config ]; then
  echo "Missing /workspace/config"
  exit 1
fi
. /workspace/config

COMMITTER_NAME=${COMMITTER_NAME:?"COMMITTER_NAME is not set"}
COMMITTER_EMAIL=${COMMITTER_EMAIL:?"COMITTER_EMAIL is not set"}
RELEASE_DATE=${RELEASE_DATE:-`date +%Y-%m-%d -d 'thursday'`}
RELEASE_BRANCH=${RELEASE_BRANCH:?"RELEASE_BRANCH is not set"}
RELEASE_VERSION=${RELEASE_VERSION:?"RELEASE_VERSION is not set"}
GPG_KEY=${GPG_KEY:?"GPG_KEY is not set"}
CONFIGURE_AC=${CONFIGURE_AC:-"configure.ac"}
MAKE_JOBS=${MAKE_JOBS:-`nproc`}
TEST_JOBS=${TEST_JOBS:-`nproc`}
UPMERGE_TO_MASTER=${UPMERGE_TO_MASTER:-no}
case "$UPMERGE_TO_MASTER" in
  yes|no) ;;
  *) echo "UPMERGE_TO_MASTER must be yes or no" >&2; exit 1 ;;
esac

PHP_REPO_FETCH=${PHP_REPO_FETCH:-"https://github.com/php/php-src"}
PHP_REPO_PUSH=${PHP_REPO_PUSH:-"git@github.com:php/php-src"}

# Translate version
RELEASE_VERSION_ORIGINAL=$RELEASE_VERSION
CONFIGURED_ZEND_VERSION=${ZEND_VERSION:-}
. /parse-version.sh "$RELEASE_VERSION"
POINT_RELEASE_BRANCH="PHP-${VERSION_MAJOR}.${VERSION_MINOR}"
RC_RELEASE_BRANCH="${POINT_RELEASE_BRANCH}.${VERSION_PATCH}"

# Prepare GPG stuff
if [ ! -d /tmp/gnupg ]; then
  echo Please bind mount your .gnupg folder to  /tmp/gnupg
  exit 1
fi

mkdir -p /root/.gnupg
chmod 700 /root/.gnupg
cp -Rp /tmp/gnupg/* /root/.gnupg/

# Force TTY pinentry
cat > /root/.gnupg/gpg-agent.conf <<EOF
default-cache-ttl 1800
max-cache-ttl 7200
pinentry-program /usr/bin/pinentry-curses
EOF

# Reveal our plan
echo "php-release builder"
echo "-------------------"
echo "COMMITTER_NAME=${COMMITTER_NAME}"
echo "COMMITTER_EMAIL=${COMMITTER_EMAIL}"
echo "GPG_KEY=${GPG_KEY}"
echo "RELEASE_DATE=${RELEASE_DATE}"
echo -n "RELEASE_BRANCH="
if [  -z "$CUT_RELEASE_BRANCH" ]; then
  echo "$RELEASE_BRANCH"
else
  echo "$CUT_RELEASE_BRANCH (new branch cut from $RELEASE_BRANCH)"
fi
echo "RELEASE_VERSION=${RELEASE_VERSION}"
echo "-------------------"

# Clean up workspace
cd /workspace
rm -rf php-src
rm -f  log/{config,make,tests}.{debug-,}[nz]ts.log

# Clone from source (using public readable),
# Configure commiter,
# then update branch to push destination
echo "Cloning from ${PHP_REPO_FETCH}"
cd /workspace

git clone -b "$RELEASE_BRANCH" -o upstream --single-branch --depth="${CLONE_DEPTH:-100}" "${PHP_REPO_FETCH}"

cd /workspace/php-src

# Discover version branches rather than assuming which version master contains.
UPMERGE_BRANCHES=
if [ "${UPMERGE_TO_MASTER:-no}" = yes ] && [ "$RELEASE_BRANCH" != master ]; then
  if [[ ! "$RELEASE_BRANCH" =~ ^PHP-([0-9]+)\.([0-9]+)(\.[0-9]+)?$ ]]; then
    echo "Cannot determine upmerge sequence for $RELEASE_BRANCH" >&2
    exit 1
  fi
  UPMERGE_MAJOR=${BASH_REMATCH[1]}
  UPMERGE_MINOR=${BASH_REMATCH[2]}
  UPMERGE_BRANCHES=$(git ls-remote --heads upstream | \
    sed -n 's@.*refs/heads/\(PHP-[0-9]\+\.[0-9]\+\)$@\1@p' | \
    sort -V | awk -F '[-.]' -v major="$UPMERGE_MAJOR" -v minor="$UPMERGE_MINOR" -v source="$RELEASE_BRANCH" \
      '$2 > major || ($2 == major && ($3 > minor || ($3 == minor && $0 != source)))')
  UPMERGE_BRANCHES="${UPMERGE_BRANCHES} master"
fi

# Fetch the discovered upmerge branches at the configured depth.
for branch in $UPMERGE_BRANCHES; do
  git check-ref-format --branch "$branch" > /dev/null
  git remote set-branches --add upstream "$branch"
  git fetch --depth="${CLONE_DEPTH:-100}" upstream "+refs/heads/${branch}:refs/remotes/upstream/${branch}"
  if ! git show-ref --verify --quiet "refs/heads/${branch}"; then
    git branch --track "$branch" "upstream/$branch"
  fi
done

if [ ! -z "${CUT_RELEASE_BRANCH}" ]; then
  echo "Cutting ${CUT_RELEASE_BRANCH} from ${RELEASE_BRANCH}"
  git checkout -b "${CUT_RELEASE_BRANCH}"
fi

echo "Building $RELEASE_VERSION from ${CUT_RELEASE_BRANCH:-$RELEASE_BRANCH}"
if [[ "$VERSION_EXTRA" != alpha* && "$VERSION_EXTRA" != beta* ]]; then
  if [ "$POINT_RELEASE_BRANCH" != "$RELEASE_BRANCH" -a \
       "$RC_RELEASE_BRANCH" != "$RELEASE_BRANCH" -a \
       "$RC_RELEASE_BRANCH" != "$CUT_RELEASE_BRANCH" ]; then
    echo "******************************************" 1>&2
    echo -n "** WARNING: $RELEASE_VERSION probably belongs on " 1>&2
    echo -n "$POINT_RELEASE_BRANCH or $RC_RELEASE_BRANCH" 1>&2
    echo ", but is being cut from $RELEASE_BRANCH" 1>&2
    echo -ne '\007\007\007'
    echo "******************************************" 1>&2
    sleep 15
  fi
fi

# Get going
git config user.name "$COMMITTER_NAME"
git config user.email "$COMMITTER_EMAIL"
git config user.signingkey  "$GPG_KEY"
git config commit.gpgsign true
git config tag.gpgSign true
git config merge.log true
# Keep NEWS for manual merging, as required by the PHP Git workflow.
git config merge.NEWS.name "Keep the NEWS file"
git config merge.NEWS.driver 'touch %A'
git remote set-url upstream --push "${PHP_REPO_PUSH}"

GPG_TTY=$(tty)
export GPG_TTY

# Update CREDITS
cd /workspace/php-src
scripts/dev/credits
if [ ! -z "$(git diff ext/standard/credits_{ext,sapi}.h)" ]; then
  git add ext/standard/credits_{ext,sapi}.h
  git commit -m "Update CREDITS for PHP ${RELEASE_VERSION}"
  git show | cat -
fi

# Bump API numbers if requested
if [[ "$BUMP_API" == "1" ]]; then
  bump_api() {
    API_DATE=$(date +%y%m%d -d "$RELEASE_DATE")
    sed -i -E -e "s/(define ${2}\\s.*)[0-9]{6}/\\1${API_DATE}/" "$1"
    git add "$1"
  }

  bump_api "main/php.h" "PHP_API_VERSION"
  bump_api "Zend/zend_extensions.h" "ZEND_EXTENSION_API_NO"
  bump_api "Zend/zend_modules.h" "ZEND_MODULE_API_NO"

  git commit -m "Prepare for PHP ${RELEASE_VERSION}"
fi

# Update NEWS
cd /workspace/php-src
NEWS_FILE_SLUG="$(date -d ${RELEASE_DATE} '+%d %b %Y'), PHP ${RELEASE_VERSION}"
NEWS_AMEND=
sed -i \
    -e "s/?? ??? \(????\|[0-9]\{4\}\),.*/${NEWS_FILE_SLUG}/g" \
    NEWS
if [ ! -z "$(git diff -- NEWS)" ]; then
  NEWS_AMEND=--amend
  git add NEWS
  git commit -m "Update NEWS for PHP ${RELEASE_VERSION}"
  git show | cat -
elif [ -z "$(grep "${NEWS_FILE_SLUG}" NEWS)" ]; then
  echo "NEWS file has neither target release date, nor ?? ??? placeholder" 1>&2
  echo "Correct this and try again." 1>&2
  exit 1
fi

# Version bump will be on a spur ending at tag:php-${RELEASE_VERSION}
# NEWS bump will be on ${RELEASE_BRANCH}
SPURBASE_COMMIT=$(git rev-parse HEAD)

# Update versions
cd /workspace/php-src
. /update-versions.sh

# commit
cd /workspace/php-src
git commit ${NEWS_AMEND} -m "Update versions for PHP ${RELEASE_VERSION}"

git show | cat -
TAG_COMMIT=$(git rev-parse HEAD)

########

make_test() {
  LOGEXT="nts"
  if [ "$2" -eq 1 ]; then
    LOGEXT="zts"
  fi
  if [ "$1" -eq 1 ]; then
    LOGEXT="debug-$LOGEXT"
  fi
  echo "----------------------"
  echo "Building PHP $LOGEXT"

  # Build PHP
  mkdir -p /workspace/log
  cd /workspace/php-src
  git clean -xfdq

  CONFIG_LOG_FILE=/workspace/log/config.$LOGEXT.log
  MAKE_LOG_FILE=/workspace/log/make.$LOGEXT.log
  TEST_LOG_FILE=/workspace/log/tests.$LOGEXT.log

  CONFIG_ARGS=""
  if [ "$1" -eq 1 ]; then
    CONFIG_ARGS="$CONFIG_ARGS --enable-debug"
  fi
  if [ "$2" -eq 1 ]; then
    CONFIG_ARGS="$CONFIG_ARGS --enable-zts"
  fi

  ./buildconf --force
  ./configure --disable-all $CONFIG_ARGS --enable-opcache-jit 2>&1 | tee $CONFIG_LOG_FILE
  make -j${MAKE_JOBS} 2>&1 | tee $MAKE_LOG_FILE

  BUILT_VERSION=$(./sapi/cli/php -n -v | head -n 1 | cut -d " " -f 2)
  if [ "$BUILT_VERSION" != "$RELEASE_VERSION" ]; then
    echo "**Panic: RELEASE_VERSION=${RELEASE_VERSION}, but BUILT_VERSION=${BUILT_VERSION}"
    exit 1
  fi

  REPORT_EXIT_STATUS="${ABORT_ON_TEST_FAILURES:-1}" \
    sapi/cli/php run-tests.php -q -j${TEST_JOBS} -s ${TEST_LOG_FILE} --show-diff -g "FAIL" --offline 2>&1
}

MAKE_TESTS="${MAKE_TESTS:-2}"
if [ "${MAKE_TESTS}" -ge 1 ]; then
  # 0 No tests
  # 1 Debug-ZTS only
  # 2 All tests
  if [ "${MAKE_TESTS}" -ge 2 ]; then
    make_test 0 0
    make_test 0 1
    make_test 1 0
  fi
  make_test 1 1
fi

# Make tarballs/stubs and relocate them
cd /workspace/php-src
echo "-----------------"
echo "Bundling tarballs"
git tag "php-$RELEASE_VERSION" "$TAG_COMMIT" -m "Tag for php-$RELEASE_VERSION"
scripts/dev/makedist "php-$RELEASE_VERSION"
scripts/dev/gen_verify_stub $RELEASE_VERSION $GPG_KEY > php-$RELEASE_VERSION.manifest

# Back off of release spur now that we've tagged
git reset "${SPURBASE_COMMIT}" --hard

# Update NEWS (if requested)
cd /workspace/php-src
if [ ! -z "$RELEASE_NEXT" ]; then
  sed -i \
      -e "3s/^/?? ??? ????, PHP ${RELEASE_NEXT}\n\n\n/" \
      NEWS
  git add NEWS

  CURRENT_RELEASE=$RELEASE_VERSION

  # Parse next version
  . /parse-version.sh ${RELEASE_NEXT}

  if [ -n "$VERSION_EXTRA" ]; then
    git commit ${NEWS_AMEND} -m "[ci skip] Update NEWS for ${RELEASE_NEXT}"
  else
    VERSION_EXTRA="-dev"
    ZEND_VERSION=$CONFIGURED_ZEND_VERSION
    . /update-versions.sh

    git commit ${NEWS_AMEND} -m "${CUT_RELEASE_BRANCH:-$RELEASE_BRANCH} is now ${RELEASE_NEXT}-dev"
  fi

  # Restore initial version
  . /parse-version.sh "$CURRENT_RELEASE"
fi

# Prepare the source version branch independently of RELEASE_NEXT on the cut branch.
if [ "$RELEASE_BRANCH" = "$POINT_RELEASE_BRANCH" ] && \
   [ "$CUT_RELEASE_BRANCH" = "$RC_RELEASE_BRANCH" ]; then
  NEXT_PATCH="${VERSION_MAJOR}.${VERSION_MINOR}.$((VERSION_PATCH + 1))"
  git checkout "$RELEASE_BRANCH"
  git checkout "$SPURBASE_COMMIT" -- NEWS
  sed -i -e "3s/^/?? ??? ????, PHP ${NEXT_PATCH}\n\n\n/" NEWS
  git add NEWS
  . /parse-version.sh "${NEXT_PATCH}-dev"
  ZEND_VERSION=$CONFIGURED_ZEND_VERSION
  . /update-versions.sh
  git commit -m "$RELEASE_BRANCH is now ${NEXT_PATCH}-dev"
  git checkout "$CUT_RELEASE_BRANCH"
  . /parse-version.sh "$RELEASE_VERSION_ORIGINAL"
fi

# Merge branch changes upwards, never the release tag's version spur.
if [ "$UPMERGE_TO_MASTER" = yes ] && [ -n "$UPMERGE_BRANCHES" ]; then
  # A merge needs common ancestry, which may be outside the configured clone depth.
  if [ "$(git rev-parse --is-shallow-repository)" = true ]; then
    git fetch --unshallow upstream
  fi
  RETURN_BRANCH=$(git branch --show-current)
  MERGE_SOURCE=$RELEASE_BRANCH
  for branch in $UPMERGE_BRANCHES; do
    git checkout "$branch"
    if ! git merge --no-ff --no-commit "$MERGE_SOURCE"; then
      if ! git rev-parse --verify -q MERGE_HEAD > /dev/null; then
        echo "Upmerge from $MERGE_SOURCE to $branch failed" >&2
        exit 1
      fi
    fi
    if git rev-parse --verify -q MERGE_HEAD > /dev/null; then
      git restore --source=HEAD --staged --worktree -- NEWS main/php_version.h Zend/zend.h "$CONFIGURE_AC"
      if [ -n "$(git diff --name-only --diff-filter=U)" ]; then
        echo "Upmerge from $MERGE_SOURCE to $branch has conflicts; resolve them and continue manually" >&2
        exit 1
      fi
      git commit -m "Merge branch '$MERGE_SOURCE' into $branch"
    fi
    MERGE_SOURCE=$branch
  done
  git checkout "$RETURN_BRANCH"
fi

if [ ! -z "$COMMITTER_UID" -a ! -z "$COMMITTER_GID" ]; then
  chown -R "${COMMITTER_UID}:${COMMITTER_GID}" /workspace/php-src
fi

# Truncate COMMIT hash for readability
TAG_COMMIT=$(echo "${TAG_COMMIT}" | cut -c 1-10)

# Output finalization instructions
echo ""
echo "-----------------"
echo "Tarballs prepared"
echo "-----------------"
echo ""

echo "Verify what you're about to push as a tagged release:"
echo "$ git log -p 'upstream/${RELEASE_BRANCH}..php-${RELEASE_VERSION}'"
echo ""
if [ ! -z "$RELEASE_NEXT" ]; then
  echo "And as the prepared NEWS entry for the next release:"
  echo "$ git log -p 'upstream/${RELEASE_BRANCH}..${CUT_RELEASE_BRANCH:-$RELEASE_BRANCH}'"
  echo ""
fi

echo "If all is well, push it to ${PHP_REPO_PUSH}!"
echo "$ git push upstream 'php-$RELEASE_VERSION' '${CUT_RELEASE_BRANCH:-${RELEASE_BRANCH}}'"
if [ "$RELEASE_BRANCH" = "$POINT_RELEASE_BRANCH" ] && \
   [ "$CUT_RELEASE_BRANCH" = "$RC_RELEASE_BRANCH" ]; then
  echo "Review the version branch bump:"
  echo "$ git log -p 'upstream/${RELEASE_BRANCH}..${RELEASE_BRANCH}'"
  echo "$ git push upstream '${RELEASE_BRANCH}'"
fi
echo ""

for branch in $UPMERGE_BRANCHES; do
  echo "Review the upmerge to $branch:"
  echo "$ git log -p 'upstream/${branch}..${branch}'"
  echo "$ git push upstream '$branch'"
done

echo "Make the tarballs available for testing:"
if [ -z "$VERSION_EXTRA" ]; then
  echo "1. Copy workspace/php-src/php-$RELEASE_VERSION.tar.{gz,bz2,xz}{,.asc} to php-distributions repository"
else
  echo "1. Copy workspace/php-src/php-$RELEASE_VERSION.tar.{gz,bz2,xz}{,.asc} to \$USER@downloads.php.net:public_html/"
fi
echo "2. Contact release-managers@php.net for Windows build creation"
if [ ! -z "${CUT_RELEASE_BRANCH}" ] && [ "$RELEASE_BRANCH" != "$POINT_RELEASE_BRANCH" ]; then
  echo "2a. Bump version in ${RELEASE_BRANCH}"
fi
if [ -z "$VERSION_EXTRA" ]; then
  echo "3. This appears to be a release build.  Reference README.RELEASE_PROCESS for further instruction."
fi
echo ""

echo "The announcement is: php-$RELEASE_VERSION.manifest"
