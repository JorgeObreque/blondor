# Imagen del sincronizador de la galería de blondor.cl (corre en jorgesv, ver README.md).
FROM node:22-bookworm-slim
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund
COPY sync.mjs ./
USER node
ENTRYPOINT ["node", "/app/sync.mjs"]
