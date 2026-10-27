FROM php:8.3-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl \
 && rm -rf /var/lib/apt/lists/*

RUN apt-get update \
 && apt-get install -y --no-install-recommends ffmpeg libheif-examples python3 python3-pip curl unzip ca-certificates \
 && python3 -m pip install -U --no-cache-dir --break-system-packages "yt-dlp[default,curl-cffi]" gallery-dl telethon \
 && (python3 -m pip install --no-cache-dir --break-system-packages --only-binary=:all: cryptg || true) \
 && ARCH="$(uname -m)" && case "$ARCH" in aarch64|arm64) D=aarch64 ;; *) D=x86_64 ;; esac \
 && curl -fsSL -o /tmp/deno.zip "https://github.com/denoland/deno/releases/latest/download/deno-${D}-unknown-linux-gnu.zip" \
 && unzip -o -q /tmp/deno.zip -d /usr/local/bin && chmod +x /usr/local/bin/deno && rm -f /tmp/deno.zip \
 && yt-dlp --version && gallery-dl --version | head -1 && deno --version | head -1 && ffmpeg -version | head -1 \
 && rm -rf /var/lib/apt/lists/* /root/.cache

RUN { \
      echo 'memory_limit=256M'; \
      echo 'post_max_size=32M'; \
      echo 'date.timezone=Asia/Tehran'; \
      echo 'display_errors=Off'; \
      echo 'log_errors=On'; \
    } > /usr/local/etc/php/conf.d/nullini.ini

WORKDIR /app
COPY . .

RUN find . -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null \
 && php tests/smoke.php

ENV LOG_TO_STDERR=1
EXPOSE 8080
CMD ["php", "bot.php"]
