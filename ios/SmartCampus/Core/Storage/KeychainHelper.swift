import Foundation
import Security

/// 系统级 Keychain 安全存储工具，用于持久化认证 Token 与唯一设备标识
final class KeychainHelper {
    static let shared = KeychainHelper()
    private let service = "com.smartcampus.ios.keychain"

    private enum Keys {
        static let authToken = "auth_token"
        static let deviceIdentifier = "device_identifier"
    }

    private init() {}

    func saveToken(_ token: String) {
        save(token, forKey: Keys.authToken)
    }

    func readToken() -> String? {
        read(forKey: Keys.authToken)
    }

    func clearToken() {
        delete(forKey: Keys.authToken)
    }

    /// 持久化唯一设备 ID，用于后端 `mobile_devices` 表绑定与多账号切换时的归属转移
    func getOrCreateDeviceIdentifier() -> String {
        if let existing = read(forKey: Keys.deviceIdentifier), !existing.isEmpty {
            return existing
        }
        let generated = UUID().uuidString.lowercased()
        save(generated, forKey: Keys.deviceIdentifier)
        return generated
    }

    private func save(_ value: String, forKey key: String) {
        guard let data = value.data(using: .utf8) else { return }
        delete(forKey: key)

        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: key,
            kSecValueData as String: data,
            kSecAttrAccessible as String: kSecAttrAccessibleAfterFirstUnlock
        ]
        SecItemAdd(query as CFDictionary, nil)
    }

    private func read(forKey key: String) -> String? {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: key,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne
        ]
        var result: AnyObject?
        let status = SecItemCopyMatching(query as CFDictionary, &result)
        guard status == errSecSuccess, let data = result as? Data else {
            return nil
        }
        return String(data: data, encoding: .utf8)
    }

    private func delete(forKey key: String) {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: key
        ]
        SecItemDelete(query as CFDictionary)
    }
}
