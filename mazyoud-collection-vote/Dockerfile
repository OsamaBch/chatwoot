# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1 — build the React/Vite frontend.
# Vite is configured (web/vite.config.ts) to emit into ../server/public, so the
# build output lands at /app/server/public inside this stage.
# ---------------------------------------------------------------------------
FROM node:20-alpine AS web
WORKDIR /app/web
COPY web/package.json web/package-lock.json* ./
RUN npm install --no-audit --no-fund
COPY web/ ./
RUN npm run build

# ---------------------------------------------------------------------------
# Stage 2 — compile the TypeScript backend to dist/.
# ---------------------------------------------------------------------------
FROM node:20-alpine AS server-build
WORKDIR /app/server
COPY server/package.json server/package-lock.json* ./
RUN npm install --no-audit --no-fund
COPY server/ ./
RUN npm run build

# ---------------------------------------------------------------------------
# Stage 3 — slim runtime image (prod deps only + compiled server + web build).
# ---------------------------------------------------------------------------
FROM node:20-alpine AS runtime
ENV NODE_ENV=production
WORKDIR /app/server

COPY server/package.json server/package-lock.json* ./
RUN npm install --omit=dev --no-audit --no-fund && npm cache clean --force

COPY --from=server-build /app/server/dist ./dist
COPY --from=web /app/server/public ./public

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD wget -qO- http://localhost:8080/healthz >/dev/null 2>&1 || exit 1

CMD ["node", "dist/index.js"]
