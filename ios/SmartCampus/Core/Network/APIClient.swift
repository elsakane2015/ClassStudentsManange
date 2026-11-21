import Foundation
import UIKit

enum APIError: LocalizedError {
    case notConfigured
    case invalidURL(String)
    case unauthorized(String)
    case forbidden(String)
    case conflict(String)
    case validation(String)
    case serverError(statusCode: Int, message: String)
    case networkError(String)
    case decodingError(String)

    var errorDescription: String? {
        switch self {
        case .notConfigured:
            return "尚未配置学校服务器地址，请先完成连接配置。"
        case .invalidURL(let msg),
             .unauthorized(let msg),
             .forbidden(let msg),
             .conflict(let msg),
             .validation(let msg),
             .networkError(let msg),
             .decodingError(let msg):
            return msg
        case .serverError(_, let msg):
            return msg
        }
    }
}

struct EmptyResponse: Decodable {}

struct MessageResponse: Decodable {
    let message: String?
    let error: String?
}

/// 零第三方依赖的原生 async/await API 客户端，动态读取当前激活的学校服务器地址与 Keychain Token
final class APIClient {
    static let shared = APIClient()

    private let session: URLSession
    private let decoder: JSONDecoder
    private let encoder: JSONEncoder

    /// 当任何接口返回 401 时触发，由 SessionManager 捕获并切回登录页
    var onUnauthorized: (@MainActor () -> Void)?

    private init() {
        let config = URLSessionConfiguration.default
        config.timeoutIntervalForRequest = 20
        config.timeoutIntervalForResource = 60
        config.waitsForConnectivity = false
        self.session = URLSession(configuration: config)

        let dec = JSONDecoder()
        self.decoder = dec

        let enc = JSONEncoder()
        self.encoder = enc
    }

    func request<T: Decodable>(
        _ path: String,
        method: String = "GET",
        query: [String: String?] = [:],
        body: Encodable? = nil,
        requiresAuth: Bool = true
    ) async throws -> T {
        let urlRequest = try await buildRequest(
            path: path,
            method: method,
            query: query,
            bodyData: body.map { try encoder.encode(AnyEncodable($0)) },
            contentType: body != nil ? "application/json" : nil,
            requiresAuth: requiresAuth
        )

        let (data, http) = try await execute(urlRequest)
        try await validateStatus(http: http, data: data, requiresAuth: requiresAuth)

        if T.self == EmptyResponse.self {
            return EmptyResponse() as! T
        }
        if data.isEmpty, let empty = EmptyResponse() as? T {
            return empty
        }

        do {
            return try decoder.decode(T.self, from: data)
        } catch {
            throw APIError.decodingError("数据解析失败：\(error.localizedDescription)")
        }
    }

    func requestRawJSON(
        _ path: String,
        method: String = "GET",
        query: [String: String?] = [:],
        jsonBody: [String: Any]? = nil,
        requiresAuth: Bool = true
    ) async throws -> Data {
        let bodyData: Data? = try jsonBody.map { try JSONSerialization.data(withJSONObject: $0) }
        let urlRequest = try await buildRequest(
            path: path,
            method: method,
            query: query,
            bodyData: bodyData,
            contentType: bodyData != nil ? "application/json" : nil,
            requiresAuth: requiresAuth
        )
        let (data, http) = try await execute(urlRequest)
        try await validateStatus(http: http, data: data, requiresAuth: requiresAuth)
        return data
    }

    func requestWithJSONBody<T: Decodable>(
        _ path: String,
        method: String = "POST",
        query: [String: String?] = [:],
        jsonBody: [String: Any],
        requiresAuth: Bool = true
    ) async throws -> T {
        let data = try await requestRawJSON(
            path,
            method: method,
            query: query,
            jsonBody: jsonBody,
            requiresAuth: requiresAuth
        )
        if T.self == EmptyResponse.self {
            return EmptyResponse() as! T
        }
        do {
            return try decoder.decode(T.self, from: data)
        } catch {
            throw APIError.decodingError("数据解析失败：\(error.localizedDescription)")
        }
    }

    /// Multipart 上传请假图片，自动按后端限制压缩 JPEG 体积
    func uploadImage<T: Decodable>(
        _ path: String,
        image: UIImage,
        fieldName: String = "image",
        maxSizeMB: Int = 5
    ) async throws -> T {
        let maxBytes = max(1, maxSizeMB) * 1024 * 1024
        guard let imageData = compressImage(image, maxBytes: maxBytes) else {
            throw APIError.validation("无法处理所选图片，请重新选择")
        }

        let boundary = "Boundary-\(UUID().uuidString)"
        var body = Data()
        let filename = "leave_\(Int(Date().timeIntervalSince1970)).jpg"

        body.append("--\(boundary)\r\n".data(using: .utf8)!)
        body.append("Content-Disposition: form-data; name=\"\(fieldName)\"; filename=\"\(filename)\"\r\n".data(using: .utf8)!)
        body.append("Content-Type: image/jpeg\r\n\r\n".data(using: .utf8)!)
        body.append(imageData)
        body.append("\r\n--\(boundary)--\r\n".data(using: .utf8)!)

        let urlRequest = try await buildRequest(
            path: path,
            method: "POST",
            query: [:],
            bodyData: body,
            contentType: "multipart/form-data; boundary=\(boundary)",
            requiresAuth: true
        )

        let (data, http) = try await execute(urlRequest)
        try await validateStatus(http: http, data: data, requiresAuth: true)

        do {
            return try decoder.decode(T.self, from: data)
        } catch {
            throw APIError.decodingError("图片上传响应解析失败：\(error.localizedDescription)")
        }
    }

    /// 下载二进制文件（如考勤 Excel 导出）至临时目录并返回本地文件 URL
    func downloadFile(
        _ path: String,
        query: [String: String?] = [:],
        suggestedFilename: String = "attendance_export.xlsx"
    ) async throws -> URL {
        let urlRequest = try await buildRequest(
            path: path,
            method: "GET",
            query: query,
            bodyData: nil,
            contentType: nil,
            requiresAuth: true,
            accept: "*/*"
        )

        let (data, http) = try await execute(urlRequest)
        try await validateStatus(http: http, data: data, requiresAuth: true)

        var fileName = suggestedFilename
        if let disposition = http.value(forHTTPHeaderField: "Content-Disposition"),
           let range = disposition.range(of: "filename=") {
            let rawName = String(disposition[range.upperBound...])
                .trimmingCharacters(in: CharacterSet(charactersIn: "\"' ;"))
            if !rawName.isEmpty {
                fileName = rawName
            }
        }

        let tempURL = FileManager.default.temporaryDirectory.appendingPathComponent(fileName)
        try data.write(to: tempURL, options: .atomic)
        return tempURL
    }

    private func buildRequest(
        path: String,
        method: String,
        query: [String: String?],
        bodyData: Data?,
        contentType: String?,
        requiresAuth: Bool,
        accept: String = "application/json"
    ) async throws -> URLRequest {
        let baseURL = await MainActor.run { ServerConfigManager.shared.activeBaseURL }
        guard let baseURL, !baseURL.isEmpty else {
            throw APIError.notConfigured
        }

        let normalizedPath = path.hasPrefix("/") ? path : "/\(path)"
        let fullURLString = "\(baseURL)/api\(normalizedPath)"

        guard var components = URLComponents(string: fullURLString) else {
            throw APIError.invalidURL("无效的接口地址：\(fullURLString)")
        }

        let queryItems = query.compactMap { key, value -> URLQueryItem? in
            guard let value, !value.isEmpty else { return nil }
            return URLQueryItem(name: key, value: value)
        }
        if !queryItems.isEmpty {
            components.queryItems = queryItems
        }

        guard let finalURL = components.url else {
            throw APIError.invalidURL("无法构建请求 URL")
        }

        var request = URLRequest(url: finalURL)
        request.httpMethod = method
        request.setValue(accept, forHTTPHeaderField: "Accept")
        request.setValue("XMLHttpRequest", forHTTPHeaderField: "X-Requested-With")

        if let contentType {
            request.setValue(contentType, forHTTPHeaderField: "Content-Type")
        }

        if requiresAuth, let token = KeychainHelper.shared.readToken(), !token.isEmpty {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }

        request.httpBody = bodyData
        return request
    }

    private func execute(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        do {
            let (data, response) = try await session.data(for: request)
            guard let http = response as? HTTPURLResponse else {
                throw APIError.networkError("未收到有效的服务器响应")
            }
            return (data, http)
        } catch let error as APIError {
            throw error
        } catch {
            let nsError = error as NSError
            if nsError.code == NSURLErrorNotConnectedToInternet {
                throw APIError.networkError("当前网络不可用，请检查 Wi-Fi 或移动数据")
            }
            if nsError.code == NSURLErrorTimedOut {
                throw APIError.networkError("连接服务器超时，请稍后重试")
            }
            throw APIError.networkError("网络请求失败：\(error.localizedDescription)")
        }
    }

    private func validateStatus(http: HTTPURLResponse, data: Data, requiresAuth: Bool) async throws {
        guard !(200...299).contains(http.statusCode) else { return }

        let message = extractServerMessage(from: data) ?? "请求失败（HTTP \(http.statusCode)）"

        switch http.statusCode {
        case 401:
            if requiresAuth {
                let handler = onUnauthorized
                await MainActor.run { handler?() }
            }
            throw APIError.unauthorized(message.isEmpty ? "登录已失效，请重新登录" : message)
        case 403:
            throw APIError.forbidden(message)
        case 409:
            throw APIError.conflict(message)
        case 422:
            throw APIError.validation(message)
        default:
            throw APIError.serverError(statusCode: http.statusCode, message: message)
        }
    }

    private func extractServerMessage(from data: Data) -> String? {
        guard let obj = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
            return nil
        }

        if let errors = obj["errors"] as? [String: Any] {
            for (_, value) in errors {
                if let list = value as? [String], let first = list.first, !first.isEmpty {
                    return first
                }
            }
        }
        if let err = obj["error"] as? String, !err.isEmpty {
            return err
        }
        if let msg = obj["message"] as? String, !msg.isEmpty {
            return msg
        }
        return nil
    }

    private func compressImage(_ image: UIImage, maxBytes: Int) -> Data? {
        let maxDimension: CGFloat = 1920
        let size = image.size
        let scaledImage: UIImage
        if max(size.width, size.height) > maxDimension {
            let ratio = maxDimension / max(size.width, size.height)
            let newSize = CGSize(width: size.width * ratio, height: size.height * ratio)
            let renderer = UIGraphicsImageRenderer(size: newSize)
            scaledImage = renderer.image { _ in
                image.draw(in: CGRect(origin: .zero, size: newSize))
            }
        } else {
            scaledImage = image
        }

        var quality: CGFloat = 0.85
        while quality >= 0.2 {
            if let data = scaledImage.jpegData(compressionQuality: quality), data.count <= maxBytes {
                return data
            }
            quality -= 0.15
        }
        return scaledImage.jpegData(compressionQuality: 0.2)
    }
}

private struct AnyEncodable: Encodable {
    private let encodeClosure: (Encoder) throws -> Void

    init(_ wrapped: Encodable) {
        self.encodeClosure = wrapped.encode
    }

    func encode(to encoder: Encoder) throws {
        try encodeClosure(encoder)
    }
}
