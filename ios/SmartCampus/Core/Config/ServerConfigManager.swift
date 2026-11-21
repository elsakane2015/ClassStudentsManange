import Foundation
import Observation

struct SavedServer: Codable, Identifiable, Equatable, Hashable {
    let id: String
    var baseURL: String
    var schoolName: String
    var lastConnectedAt: Date

    init(baseURL: String, schoolName: String, lastConnectedAt: Date = Date()) {
        self.id = baseURL
        self.baseURL = baseURL
        self.schoolName = schoolName
        self.lastConnectedAt = lastConnectedAt
    }
}

struct SchoolInfoResponse: Codable {
    let id: Int?
    let name: String?
    let logo: String?
    let timezone: String?
}

/// 动态多校服务器地址管理器（不写死任何固定域名）
@MainActor
@Observable
final class ServerConfigManager {
    static let shared = ServerConfigManager()

    private enum StorageKeys {
        static let activeBaseURL = "smartcampus.active_base_url"
        static let activeSchoolName = "smartcampus.active_school_name"
        static let savedServers = "smartcampus.saved_servers"
    }

    var activeBaseURL: String? {
        didSet {
            UserDefaults.standard.set(activeBaseURL, forKey: StorageKeys.activeBaseURL)
        }
    }

    var activeSchoolName: String {
        didSet {
            UserDefaults.standard.set(activeSchoolName, forKey: StorageKeys.activeSchoolName)
        }
    }

    var savedServers: [SavedServer] = [] {
        didSet {
            if let data = try? JSONEncoder().encode(savedServers) {
                UserDefaults.standard.set(data, forKey: StorageKeys.savedServers)
            }
        }
    }

    var isConfigured: Bool {
        guard let url = activeBaseURL, !url.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else {
            return false
        }
        return URL(string: url) != nil
    }

    private init() {
        self.activeBaseURL = UserDefaults.standard.string(forKey: StorageKeys.activeBaseURL)
        self.activeSchoolName = UserDefaults.standard.string(forKey: StorageKeys.activeSchoolName) ?? "智慧校园"
        if let data = UserDefaults.standard.data(forKey: StorageKeys.savedServers),
           let decoded = try? JSONDecoder().decode([SavedServer].self, from: data) {
            self.savedServers = decoded
        }
    }

    /// 规范化用户输入的学校域名/地址（自动补全协议，剔除末尾斜杠及误输入的 /api）
    nonisolated static func normalizeBaseURL(_ raw: String) -> String? {
        var trimmed = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return nil }

        if !trimmed.lowercased().hasPrefix("http://") && !trimmed.lowercased().hasPrefix("https://") {
            let hostPart = trimmed.split(separator: "/").first.map(String.init) ?? trimmed
            let isLocalOrIP = hostPart.hasPrefix("localhost")
                || hostPart.hasPrefix("127.")
                || hostPart.hasPrefix("10.")
                || hostPart.hasPrefix("192.168.")
                || hostPart.hasPrefix("172.")
                || hostPart.contains(":")
            trimmed = (isLocalOrIP ? "http://" : "https://") + trimmed
        }

        while trimmed.hasSuffix("/") {
            trimmed.removeLast()
        }

        if trimmed.lowercased().hasSuffix("/api") {
            trimmed = String(trimmed.dropLast(4))
        }

        guard let components = URLComponents(string: trimmed),
              let scheme = components.scheme?.lowercased(),
              (scheme == "http" || scheme == "https"),
              let host = components.host, !host.isEmpty else {
            return nil
        }

        return trimmed
    }

    /// 探测服务器连通性并读取学校名称
    func probeServer(_ rawInput: String) async throws -> (baseURL: String, schoolName: String) {
        guard let normalized = Self.normalizeBaseURL(rawInput),
              let probeURL = URL(string: "\(normalized)/api/school/info") else {
            throw APIError.invalidURL("服务器地址格式不正确，例如 https://school.example.com")
        }

        var request = URLRequest(url: probeURL)
        request.httpMethod = "GET"
        request.timeoutInterval = 10
        request.setValue("application/json", forHTTPHeaderField: "Accept")

        let (data, response) = try await URLSession.shared.data(for: request)
        guard let http = response as? HTTPURLResponse else {
            throw APIError.networkError("服务器未返回有效 HTTP 响应")
        }

        guard (200...299).contains(http.statusCode) else {
            throw APIError.serverError(statusCode: http.statusCode, message: "无法连接该学校服务器（HTTP \(http.statusCode)）")
        }

        let info = try? JSONDecoder().decode(SchoolInfoResponse.self, from: data)
        let name = info?.name?.trimmingCharacters(in: .whitespacesAndNewlines)
        let resolvedName = (name?.isEmpty == false) ? name! : (URL(string: normalized)?.host ?? "智慧校园")
        return (normalized, resolvedName)
    }

    /// 保存并切换至指定学校站点
    func applyServer(baseURL: String, schoolName: String) {
        activeBaseURL = baseURL
        activeSchoolName = schoolName

        var list = savedServers.filter { $0.baseURL != baseURL }
        list.insert(SavedServer(baseURL: baseURL, schoolName: schoolName, lastConnectedAt: Date()), at: 0)
        savedServers = Array(list.prefix(10))
    }

    func removeSavedServer(_ server: SavedServer) {
        savedServers.removeAll { $0.baseURL == server.baseURL }
        if activeBaseURL == server.baseURL {
            activeBaseURL = savedServers.first?.baseURL
            activeSchoolName = savedServers.first?.schoolName ?? "智慧校园"
        }
    }

    /// 智能解析请假凭证图片等相对/绝对媒体路径（规避后端 APP_URL 误配为 localhost）
    func resolveMediaURL(_ pathOrURL: String) -> URL? {
        let cleaned = pathOrURL.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !cleaned.isEmpty else { return nil }

        guard let base = activeBaseURL else {
            return URL(string: cleaned)
        }

        if cleaned.hasPrefix("http://") || cleaned.hasPrefix("https://") {
            if let parsed = URL(string: cleaned),
               let host = parsed.host?.lowercased(),
               (host == "localhost" || host == "127.0.0.1"),
               let baseURL = URL(string: base) {
                var components = URLComponents(url: parsed, resolvingAgainstBaseURL: false)
                components?.scheme = baseURL.scheme
                components?.host = baseURL.host
                components?.port = baseURL.port
                return components?.url ?? parsed
            }
            return URL(string: cleaned)
        }

        if cleaned.hasPrefix("/storage/") {
            return URL(string: base + cleaned)
        }
        if cleaned.hasPrefix("storage/") {
            return URL(string: "\(base)/\(cleaned)")
        }
        if cleaned.hasPrefix("/") {
            return URL(string: base + cleaned)
        }
        return URL(string: "\(base)/storage/\(cleaned)")
    }
}
