package main

import (
	"bytes"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"encoding/json"
	"encoding/pem"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func createTestKeyFile(t *testing.T) string {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	der, err := x509.MarshalPKCS8PrivateKey(key)
	if err != nil {
		t.Fatal(err)
	}
	pemBytes := pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: der})
	path := filepath.Join(t.TempDir(), "test.p8")
	if err := os.WriteFile(path, pemBytes, 0o600); err != nil {
		t.Fatal(err)
	}
	return path
}

func TestRelaySendAndAuth(t *testing.T) {
	var capturedTopic, capturedEnv, capturedAuth string
	mockAPNs := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		capturedTopic = r.Header.Get("apns-topic")
		capturedAuth = r.Header.Get("authorization")
		if strings.HasSuffix(r.URL.Path, "/deadbeefdeadbeefdeadbeefdeadbeef") {
			w.WriteHeader(http.StatusBadRequest)
			_, _ = w.Write([]byte(`{"reason":"BadDeviceToken"}`))
			return
		}
		capturedEnv = "ok"
		w.Header().Set("apns-id", "test-apns-id-123")
		w.WriteHeader(http.StatusOK)
	}))
	defer mockAPNs.Close()

	keyFile := createTestKeyFile(t)
	apns, err := NewAPNSClient(APNSConfig{
		TeamID:         "HCDD4TQBT8",
		KeyID:          "JF4XKNHTQG",
		KeyFile:        keyFile,
		ProductionBase: mockAPNs.URL,
		SandboxBase:    mockAPNs.URL,
	})
	if err != nil {
		t.Fatalf("NewAPNSClient failed: %v", err)
	}

	const token = "super-secret-relay-token-0123456789abcdef"
	relay, err := NewRelayServer(apns, []string{"com.litotime.smartcampus"}, token)
	if err != nil {
		t.Fatalf("NewRelayServer failed: %v", err)
	}

	// 1. Health check works without auth
	req := httptest.NewRequest(http.MethodGet, "/healthz", nil)
	rec := httptest.NewRecorder()
	relay.Handler().ServeHTTP(rec, req)
	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200 on /healthz, got %d", rec.Code)
	}

	// 2. Unauthorized rejected
	req = httptest.NewRequest(http.MethodPost, "/v1/apns/send", strings.NewReader(`{}`))
	req.Header.Set("Content-Type", "application/json")
	rec = httptest.NewRecorder()
	relay.Handler().ServeHTTP(rec, req)
	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("expected 401 without bearer token, got %d", rec.Code)
	}

	// 3. Disallowed bundle rejected
	body, _ := json.Marshal(SendRequest{
		DeviceToken: "aabbccddeeff00112233445566778899aabbccddeeff00112233445566778899",
		BundleID:    "com.other.app",
		Title:       "测试",
		Body:        "内容",
	})
	req = httptest.NewRequest(http.MethodPost, "/v1/apns/send", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+token)
	rec = httptest.NewRecorder()
	relay.Handler().ServeHTTP(rec, req)
	if rec.Code != http.StatusForbidden {
		t.Fatalf("expected 403 for disallowed bundle, got %d", rec.Code)
	}

	// 4. Valid request succeeds
	badge := 3
	body, _ = json.Marshal(SendRequest{
		DeviceToken: "aabbccddeeff00112233445566778899aabbccddeeff00112233445566778899",
		BundleID:    "com.litotime.smartcampus",
		Environment: "development",
		Title:       "新的请假申请",
		Body:        "张三提交了病假申请",
		Badge:       &badge,
		Data:        map[string]any{"type": "leave_request_submitted"},
	})
	req = httptest.NewRequest(http.MethodPost, "/v1/apns/send", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+token)
	rec = httptest.NewRecorder()
	relay.Handler().ServeHTTP(rec, req)
	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200 on valid send, got %d: %s", rec.Code, rec.Body.String())
	}
	if capturedTopic != "com.litotime.smartcampus" || !strings.HasPrefix(capturedAuth, "bearer ") || capturedEnv != "ok" {
		t.Fatalf("unexpected APNs headers: topic=%q auth=%q", capturedTopic, capturedAuth)
	}

	// 5. BadDeviceToken marks invalid_token=true
	body, _ = json.Marshal(SendRequest{
		DeviceToken: "deadbeefdeadbeefdeadbeefdeadbeef",
		BundleID:    "com.litotime.smartcampus",
		Environment: "development",
		Title:       "测试",
		Body:        "失效 token",
	})
	req = httptest.NewRequest(http.MethodPost, "/v1/apns/send", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+token)
	rec = httptest.NewRecorder()
	relay.Handler().ServeHTTP(rec, req)
	var sendRes SendResponse
	_ = json.NewDecoder(rec.Body).Decode(&sendRes)
	if sendRes.OK || !sendRes.InvalidToken || sendRes.Reason != "BadDeviceToken" {
		t.Fatalf("expected invalid_token=true for BadDeviceToken, got %+v", sendRes)
	}
}
