package main

import (
	"bytes"
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"crypto/x509"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
)

type APNSConfig struct {
	TeamID           string
	KeyID            string
	KeyFile          string
	AllowedBundleIDs []string
	AuthToken        string
	ProductionBase   string
	SandboxBase      string
}

type APNSClient struct {
	teamID         string
	keyID          string
	key            *ecdsa.PrivateKey
	client         *http.Client
	productionBase string
	sandboxBase    string

	mu       sync.Mutex
	jwt      string
	issuedAt time.Time
}

func NewAPNSClient(cfg APNSConfig) (*APNSClient, error) {
	if cfg.TeamID == "" || cfg.KeyID == "" || cfg.KeyFile == "" {
		return nil, errors.New("APNS_TEAM_ID, APNS_KEY_ID and APNS_KEY_FILE are required")
	}
	if strings.ContainsAny(cfg.TeamID+cfg.KeyID, "\r\n") {
		return nil, errors.New("invalid APNS team/key identifier")
	}
	raw, err := os.ReadFile(cfg.KeyFile)
	if err != nil {
		return nil, fmt.Errorf("unable to read APNs private key file: %w", err)
	}
	block, _ := pem.Decode(raw)
	if block == nil {
		return nil, errors.New("invalid APNs PEM key")
	}
	parsed, err := x509.ParsePKCS8PrivateKey(block.Bytes)
	if err != nil {
		return nil, errors.New("invalid APNs PKCS8 private key")
	}
	ecKey, ok := parsed.(*ecdsa.PrivateKey)
	if !ok || ecKey.Curve != elliptic.P256() {
		return nil, errors.New("APNs requires an EC P-256 private key")
	}

	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.ForceAttemptHTTP2 = true
	transport.MaxConnsPerHost = 16
	transport.IdleConnTimeout = 90 * time.Second

	prodBase := strings.TrimRight(cfg.ProductionBase, "/")
	if prodBase == "" {
		prodBase = "https://api.push.apple.com"
	}
	sandBase := strings.TrimRight(cfg.SandboxBase, "/")
	if sandBase == "" {
		sandBase = "https://api.sandbox.push.apple.com"
	}

	return &APNSClient{
		teamID:         cfg.TeamID,
		keyID:          cfg.KeyID,
		key:            ecKey,
		productionBase: prodBase,
		sandboxBase:    sandBase,
		client: &http.Client{
			Transport: transport,
			Timeout:   15 * time.Second,
			CheckRedirect: func(_ *http.Request, _ []*http.Request) error {
				return http.ErrUseLastResponse
			},
		},
	}, nil
}

func (a *APNSClient) providerToken(forceRefresh bool) (string, error) {
	a.mu.Lock()
	defer a.mu.Unlock()
	if !forceRefresh && a.jwt != "" && time.Since(a.issuedAt) < 50*time.Minute {
		return a.jwt, nil
	}
	now := time.Now()
	header, _ := json.Marshal(map[string]string{"alg": "ES256", "kid": a.keyID})
	claims, _ := json.Marshal(map[string]any{"iss": a.teamID, "iat": now.Unix()})
	unsigned := base64.RawURLEncoding.EncodeToString(header) + "." + base64.RawURLEncoding.EncodeToString(claims)
	digest := sha256.Sum256([]byte(unsigned))
	r, s, err := ecdsa.Sign(rand.Reader, a.key, digest[:])
	if err != nil {
		return "", err
	}
	sig := make([]byte, 64)
	r.FillBytes(sig[:32])
	s.FillBytes(sig[32:])
	a.jwt = unsigned + "." + base64.RawURLEncoding.EncodeToString(sig)
	a.issuedAt = now
	return a.jwt, nil
}

type SendRequest struct {
	DeviceToken string         `json:"device_token"`
	BundleID    string         `json:"bundle_id"`
	Environment string         `json:"environment,omitempty"`
	Sandbox     *bool          `json:"sandbox,omitempty"`
	Title       string         `json:"title"`
	Subtitle    string         `json:"subtitle,omitempty"`
	Body        string         `json:"body"`
	Badge       *int           `json:"badge,omitempty"`
	Sound       string         `json:"sound,omitempty"`
	ThreadID    string         `json:"thread_id,omitempty"`
	CollapseID  string         `json:"collapse_id,omitempty"`
	Data        map[string]any `json:"data,omitempty"`
}

type SendResponse struct {
	OK           bool   `json:"ok"`
	Status       int    `json:"status"`
	Environment  string `json:"environment"`
	APNsID       string `json:"apns_id,omitempty"`
	Reason       string `json:"reason,omitempty"`
	InvalidToken bool   `json:"invalid_token"`
	Retryable    bool   `json:"retryable"`
}

func buildAPNSPayload(req SendRequest) ([]byte, error) {
	alert := map[string]any{
		"title": req.Title,
		"body":  req.Body,
	}
	if req.Subtitle != "" {
		alert["subtitle"] = req.Subtitle
	}
	sound := req.Sound
	if sound == "" {
		sound = "default"
	}
	aps := map[string]any{
		"alert": alert,
		"sound": sound,
	}
	if req.Badge != nil && *req.Badge >= 0 {
		aps["badge"] = *req.Badge
	}
	if req.ThreadID != "" {
		aps["thread-id"] = req.ThreadID
	}

	root := map[string]any{
		"aps": aps,
	}
	if len(req.Data) > 0 {
		root["data"] = req.Data
	}

	payload, err := json.Marshal(root)
	if err != nil {
		return nil, err
	}
	if len(payload) > 4096 {
		return nil, errors.New("APNs payload exceeds 4096 bytes")
	}
	return payload, nil
}

func resolveEnvironment(req SendRequest) (string, error) {
	env := strings.ToLower(strings.TrimSpace(req.Environment))
	switch env {
	case "development", "sandbox":
		return "development", nil
	case "production", "prod":
		return "production", nil
	case "":
		if req.Sandbox != nil && *req.Sandbox {
			return "development", nil
		}
		return "production", nil
	default:
		return "", errors.New("environment must be development or production")
	}
}

func (a *APNSClient) Send(ctx context.Context, req SendRequest, environment string) SendResponse {
	payload, err := buildAPNSPayload(req)
	if err != nil {
		return SendResponse{OK: false, Status: 400, Environment: environment, Reason: "payload_too_large"}
	}

	res := a.sendOnce(ctx, req, environment, payload, false)
	if !res.OK && res.Reason == "ExpiredProviderToken" {
		res = a.sendOnce(ctx, req, environment, payload, true)
	}
	return res
}

func (a *APNSClient) sendOnce(ctx context.Context, req SendRequest, environment string, payload []byte, forceRefresh bool) SendResponse {
	token, err := a.providerToken(forceRefresh)
	if err != nil {
		return SendResponse{OK: false, Status: 500, Environment: environment, Reason: "provider_signing_failed", Retryable: true}
	}

	baseURL := a.productionBase
	if environment == "development" {
		baseURL = a.sandboxBase
	}
	endpoint := baseURL + "/3/device/" + req.DeviceToken

	httpReq, err := http.NewRequestWithContext(ctx, http.MethodPost, endpoint, bytes.NewReader(payload))
	if err != nil {
		return SendResponse{OK: false, Status: 400, Environment: environment, Reason: "invalid_request"}
	}
	httpReq.Header.Set("authorization", "bearer "+token)
	httpReq.Header.Set("content-type", "application/json")
	httpReq.Header.Set("apns-topic", req.BundleID)
	httpReq.Header.Set("apns-push-type", "alert")
	httpReq.Header.Set("apns-priority", "10")
	if req.CollapseID != "" && len(req.CollapseID) <= 64 && !strings.ContainsAny(req.CollapseID, "\r\n") {
		httpReq.Header.Set("apns-collapse-id", req.CollapseID)
	}

	resp, err := a.client.Do(httpReq)
	if err != nil {
		return SendResponse{OK: false, Status: 502, Environment: environment, Reason: "apns_transport_failed", Retryable: true}
	}
	defer resp.Body.Close()

	apnsID := resp.Header.Get("apns-id")
	if resp.StatusCode == http.StatusOK {
		_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 1024))
		return SendResponse{OK: true, Status: http.StatusOK, Environment: environment, APNsID: apnsID}
	}

	var failure struct {
		Reason string `json:"reason"`
	}
	_ = json.NewDecoder(io.LimitReader(resp.Body, 4096)).Decode(&failure)
	reason := failure.Reason
	if reason == "" {
		reason = "apns_status_" + strconv.Itoa(resp.StatusCode)
	}

	invalidToken := resp.StatusCode == http.StatusGone ||
		reason == "Unregistered" ||
		reason == "BadDeviceToken" ||
		reason == "DeviceTokenNotForTopic"

	retryable := resp.StatusCode == http.StatusTooManyRequests ||
		resp.StatusCode >= 500 ||
		reason == "ExpiredProviderToken" ||
		reason == "TooManyRequests"

	return SendResponse{
		OK:           false,
		Status:       resp.StatusCode,
		Environment:  environment,
		APNsID:       apnsID,
		Reason:       reason,
		InvalidToken: invalidToken,
		Retryable:    retryable,
	}
}

type rateWindow struct {
	started time.Time
	count   int
}

type RelayServer struct {
	apns           *APNSClient
	allowedBundles map[string]bool
	bundleList     []string
	authToken      string

	limiterMu sync.Mutex
	limits    map[string]rateWindow
}

func NewRelayServer(apns *APNSClient, allowedBundles []string, authToken string) (*RelayServer, error) {
	if apns == nil {
		return nil, errors.New("apns client is required")
	}
	if len(authToken) < 24 {
		return nil, errors.New("RELAY_AUTH_TOKEN must be at least 24 characters")
	}
	allowed := map[string]bool{}
	var list []string
	for _, raw := range allowedBundles {
		b := strings.TrimSpace(raw)
		if b == "" {
			continue
		}
		if len(b) < 3 || strings.ContainsAny(b, " /\r\n") {
			return nil, fmt.Errorf("invalid bundle ID: %q", b)
		}
		if !allowed[b] {
			allowed[b] = true
			list = append(list, b)
		}
	}
	if len(list) == 0 {
		return nil, errors.New("APNS_ALLOWED_BUNDLE_IDS must contain at least one bundle identifier")
	}
	return &RelayServer{
		apns:           apns,
		allowedBundles: allowed,
		bundleList:     list,
		authToken:      authToken,
		limits:         map[string]rateWindow{},
	}, nil
}

func (s *RelayServer) allow(key string, limit int) bool {
	s.limiterMu.Lock()
	defer s.limiterMu.Unlock()
	now := time.Now()
	w := s.limits[key]
	if now.Sub(w.started) >= time.Minute {
		w = rateWindow{started: now}
	}
	w.count++
	if len(s.limits) > 4096 {
		for k, v := range s.limits {
			if now.Sub(v.started) >= time.Minute {
				delete(s.limits, k)
			}
		}
	}
	s.limits[key] = w
	return w.count <= limit
}

func (s *RelayServer) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", s.handleHealthz)
	mux.HandleFunc("POST /v1/apns/send", s.handleSend)

	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Cache-Control", "no-store")
		w.Header().Set("X-Content-Type-Options", "nosniff")
		if r.URL.Path != "/healthz" {
			ip, _, err := net.SplitHostPort(r.RemoteAddr)
			if err != nil {
				ip = r.RemoteAddr
			}
			if !s.allow("ip:"+ip, 600) {
				writeJSON(w, http.StatusTooManyRequests, map[string]any{"ok": false, "reason": "rate_limited"})
				return
			}
		}
		mux.ServeHTTP(w, r)
	})
}

func (s *RelayServer) handleHealthz(w http.ResponseWriter, _ *http.Request) {
	writeJSON(w, http.StatusOK, map[string]any{
		"status":          "ok",
		"service":         "smartcampus-push-relay",
		"allowed_bundles": s.bundleList,
	})
}

func (s *RelayServer) handleSend(w http.ResponseWriter, r *http.Request) {
	auth := r.Header.Get("Authorization")
	if !strings.HasPrefix(auth, "Bearer ") {
		writeJSON(w, http.StatusUnauthorized, map[string]any{"ok": false, "reason": "unauthorized"})
		return
	}
	provided := strings.TrimPrefix(auth, "Bearer ")
	if subtle.ConstantTimeCompare([]byte(provided), []byte(s.authToken)) != 1 {
		writeJSON(w, http.StatusUnauthorized, map[string]any{"ok": false, "reason": "unauthorized"})
		return
	}
	if !strings.HasPrefix(r.Header.Get("Content-Type"), "application/json") {
		writeJSON(w, http.StatusUnsupportedMediaType, map[string]any{"ok": false, "reason": "json_required"})
		return
	}

	r.Body = http.MaxBytesReader(w, r.Body, 16*1024)
	var req SendRequest
	dec := json.NewDecoder(r.Body)
	if err := dec.Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "reason": "invalid_json"})
		return
	}

	req.DeviceToken = strings.ToLower(strings.TrimSpace(req.DeviceToken))
	if len(req.DeviceToken) < 32 || len(req.DeviceToken) > 512 {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "reason": "invalid_device_token"})
		return
	}
	if _, err := hex.DecodeString(req.DeviceToken); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "reason": "invalid_device_token"})
		return
	}

	req.BundleID = strings.TrimSpace(req.BundleID)
	if req.BundleID == "" && len(s.bundleList) == 1 {
		req.BundleID = s.bundleList[0]
	}
	if !s.allowedBundles[req.BundleID] {
		writeJSON(w, http.StatusForbidden, map[string]any{"ok": false, "reason": "bundle_not_allowed"})
		return
	}

	req.Title = strings.TrimSpace(req.Title)
	req.Body = strings.TrimSpace(req.Body)
	if req.Title == "" && req.Body == "" {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "reason": "empty_notification"})
		return
	}

	environment, err := resolveEnvironment(req)
	if err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "reason": "invalid_environment"})
		return
	}

	result := s.apns.Send(r.Context(), req, environment)
	httpStatus := http.StatusOK
	if !result.OK {
		if result.Status >= 400 && result.Status < 600 {
			httpStatus = result.Status
		} else {
			httpStatus = http.StatusBadGateway
		}
	}
	writeJSON(w, httpStatus, result)
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

func envOr(key, fallback string) string {
	if v := strings.TrimSpace(os.Getenv(key)); v != "" {
		return v
	}
	return fallback
}

func main() {
	listen := flag.String("listen", envOr("RELAY_LISTEN", "0.0.0.0:8092"), "HTTP listen address")
	flag.Parse()

	bundles := strings.Split(envOr("APNS_ALLOWED_BUNDLE_IDS", "com.litotime.smartcampus"), ",")
	apns, err := NewAPNSClient(APNSConfig{
		TeamID:           os.Getenv("APNS_TEAM_ID"),
		KeyID:            os.Getenv("APNS_KEY_ID"),
		KeyFile:          os.Getenv("APNS_KEY_FILE"),
		AllowedBundleIDs: bundles,
		AuthToken:        os.Getenv("RELAY_AUTH_TOKEN"),
	})
	if err != nil {
		log.Fatalf("APNs init failed: %v", err)
	}

	relay, err := NewRelayServer(apns, bundles, os.Getenv("RELAY_AUTH_TOKEN"))
	if err != nil {
		log.Fatalf("Relay server init failed: %v", err)
	}

	srv := &http.Server{
		Addr:              *listen,
		Handler:           relay.Handler(),
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       15 * time.Second,
		WriteTimeout:      20 * time.Second,
		IdleTimeout:       60 * time.Second,
		MaxHeaderBytes:    16 * 1024,
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	go func() {
		log.Printf("SmartCampus APNs relay listening on %s (bundles=%v)", *listen, relay.bundleList)
		if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			log.Fatalf("HTTP server error: %v", err)
		}
	}()

	<-ctx.Done()
	shutdownCtx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()
	if err := srv.Shutdown(shutdownCtx); err != nil {
		log.Printf("graceful shutdown error: %v", err)
	}
}
