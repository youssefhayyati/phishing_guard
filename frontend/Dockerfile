# ---- Nuxt frontend (build) ----
FROM node:22-alpine AS build

WORKDIR /app

# Install deps first for caching
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

# Build the app. API base is baked for the client bundle (SPA).
ARG NUXT_PUBLIC_API_BASE=http://localhost:8000
ENV NUXT_PUBLIC_API_BASE=$NUXT_PUBLIC_API_BASE

COPY frontend/ ./
RUN npm run build

# ---- Nuxt frontend (runtime) ----
FROM node:22-alpine AS runtime

WORKDIR /app
ENV NODE_ENV=production
ENV HOST=0.0.0.0
ENV PORT=3000
ENV NUXT_PUBLIC_API_BASE=http://localhost:8000

# Only the built server output is needed to run
COPY --from=build /app/.output ./.output

EXPOSE 3000
CMD ["node", ".output/server/index.mjs"]
