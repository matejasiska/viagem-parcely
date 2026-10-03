FROM ghcr.io/osgeo/gdal:alpine-small-latest

RUN apk add --no-cache postgresql17-client curl

# Spouštím přes sh, aby import nezávisel na exec bitu bind mountu z Windows.
ENTRYPOINT ["sh", "/import/import.sh"]
