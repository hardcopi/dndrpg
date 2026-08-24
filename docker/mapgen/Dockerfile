# The map service. See src/server.mjs for what it is and why it exists.
#
# Bundled at image build rather than run from source: the generator's own files
# import each other without file extensions ("./caves"), which is what a bundler
# expects and what Node's own type stripping refuses. esbuild resolves those and
# strips the types in one pass, so the running image is plain JavaScript and
# carries no toolchain.
FROM node:22-alpine AS build
WORKDIR /build
COPY package.json ./
RUN npm install --no-audit --no-fund
COPY src ./src
RUN npx esbuild src/server.mjs --bundle --platform=node --format=esm \
        --target=node22 --outfile=server.js

FROM node:22-alpine
WORKDIR /app
COPY --from=build /build/server.js ./server.js
ENV PORT=8091
EXPOSE 8091
# The whole service is one file and the runtime it came with.
CMD ["node", "server.js"]
