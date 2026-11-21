FROM golang:1.23-alpine AS builder
WORKDIR /src
COPY go.mod main.go ./
RUN CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -trimpath -ldflags="-s -w" -o /out/smartcampus-push-relay .

FROM alpine:3.20
RUN apk add --no-cache ca-certificates tzdata \
    && addgroup -g 10001 -S relay \
    && adduser -u 10001 -S -G relay -H -s /sbin/nologin relay
COPY --from=builder /out/smartcampus-push-relay /usr/local/bin/smartcampus-push-relay
USER 10001:10001
EXPOSE 8092
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
  CMD wget -q -O /dev/null http://127.0.0.1:8092/healthz || exit 1
ENTRYPOINT ["/usr/local/bin/smartcampus-push-relay"]
