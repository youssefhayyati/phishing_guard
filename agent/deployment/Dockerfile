# PhishGuard agent image.
# Playwright browser binaries are installed as part of the versioned image
# build (STRATEGY.md section 6.3). Runs as a non-root user (section 7.2).
FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /opt/phishguard-agent

# Dependencies first for layer caching.
COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt \
    && python -m playwright install --with-deps chromium

# Application code.
COPY app ./app
COPY pyproject.toml ./

# Non-root runtime user.
RUN useradd --create-home --uid 10001 agent \
    && chown -R agent:agent /opt/phishguard-agent
USER agent

ENTRYPOINT ["python", "-m", "app.main"]
