# Production-shaped Browser Inspector worker image.
#
# Version pinning is deliberate and load-bearing: the base image tag and
# the playwright npm package version below MUST match exactly. Using
# `:latest` caused a real, reproduced version mismatch during the
# investigation PoC (the base image resolved to an older Chromium than
# the npm package expected, and the container failed to launch at all).
# See README.md "Version pinning rule".
FROM mcr.microsoft.com/playwright:v1.63.0-noble

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --omit=dev

COPY src ./src

# The base image's own non-root `pwuser` runs Chromium with its
# sandbox enabled — no --privileged, no --cap-add=SYS_ADMIN, and no
# --no-sandbox flag are required. Confirmed directly against this
# exact image/user combination on the real deployment target (Ubuntu
# Server, ai-box) during the investigation PoC.
RUN chown -R pwuser:pwuser /app
USER pwuser

ENTRYPOINT ["node", "src/cli.js"]
