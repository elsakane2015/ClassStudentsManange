import Foundation

/// 兼容 MySQL PDO 返回的字符串数字（"12"）、整数（12）、浮点数（12.0）或 null
struct FlexibleInt: Codable, Hashable, Equatable {
    let value: Int

    init(_ value: Int) {
        self.value = value
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.singleValueContainer()
        if let intVal = try? container.decode(Int.self) {
            self.value = intVal
        } else if let strVal = try? container.decode(String.self), let parsed = Int(strVal.trimmingCharacters(in: .whitespaces)) {
            self.value = parsed
        } else if let dblVal = try? container.decode(Double.self) {
            self.value = Int(dblVal)
        } else if let boolVal = try? container.decode(Bool.self) {
            self.value = boolVal ? 1 : 0
        } else {
            self.value = 0
        }
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.singleValueContainer()
        try container.encode(value)
    }
}

/// 兼容字符串数字/整数/浮点数
struct FlexibleDouble: Codable, Hashable, Equatable {
    let value: Double

    init(_ value: Double) {
        self.value = value
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.singleValueContainer()
        if let dblVal = try? container.decode(Double.self) {
            self.value = dblVal
        } else if let intVal = try? container.decode(Int.self) {
            self.value = Double(intVal)
        } else if let strVal = try? container.decode(String.self), let parsed = Double(strVal.trimmingCharacters(in: .whitespaces)) {
            self.value = parsed
        } else {
            self.value = 0
        }
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.singleValueContainer()
        try container.encode(value)
    }
}

/// 兼容 PHP/MySQL 的 0/1 与 Bool
struct FlexibleBool: Codable, Hashable, Equatable {
    let value: Bool

    init(_ value: Bool) {
        self.value = value
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.singleValueContainer()
        if let boolVal = try? container.decode(Bool.self) {
            self.value = boolVal
        } else if let intVal = try? container.decode(Int.self) {
            self.value = intVal != 0
        } else if let strVal = try? container.decode(String.self) {
            let lowered = strVal.lowercased()
            self.value = (lowered == "true" || lowered == "1" || lowered == "yes")
        } else {
            self.value = false
        }
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.singleValueContainer()
        try container.encode(value)
    }
}

/// 兼容既可能是 Int 也可能是 String 的 ID（如 overview 的 department.id = "uncategorized" 或 enrollment_year = "-"）
struct FlexibleString: Codable, Hashable, Equatable {
    let value: String

    init(_ value: String) {
        self.value = value
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.singleValueContainer()
        if let str = try? container.decode(String.self) {
            self.value = str
        } else if let intVal = try? container.decode(Int.self) {
            self.value = String(intVal)
        } else if let dblVal = try? container.decode(Double.self) {
            self.value = String(dblVal)
        } else {
            self.value = ""
        }
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.singleValueContainer()
        try container.encode(value)
    }
}

/// 解决 PHP json_encode 将空关联数组序列化为 JSON `[]` 而非 `{}` 的问题
struct PHPMap<Value: Decodable>: Decodable {
    let dictionary: [String: Value]

    init(_ dictionary: [String: Value] = [:]) {
        self.dictionary = dictionary
    }

    init(from decoder: Decoder) throws {
        if let dict = try? decoder.singleValueContainer().decode([String: Value].self) {
            self.dictionary = dict
            return
        }
        // PHP empty associative array -> []
        if let unkeyed = try? decoder.unkeyedContainer(), unkeyed.isAtEnd {
            self.dictionary = [:]
            return
        }
        self.dictionary = [:]
    }
}

/// 兼容 PHP 对象或空数组 `[]` 的任意包装器
struct PHPObject<T: Decodable>: Decodable {
    let value: T?

    init(_ value: T? = nil) {
        self.value = value
    }

    init(from decoder: Decoder) throws {
        if let decoded = try? T(from: decoder) {
            self.value = decoded
            return
        }
        // 兼容后端历史记录将 details 序列化为 JSON 字符串的情况
        if let single = try? decoder.singleValueContainer(),
           let rawString = try? single.decode(String.self),
           let data = rawString.data(using: .utf8),
           let decoded = try? JSONDecoder().decode(T.self, from: data) {
            self.value = decoded
            return
        }
        self.value = nil
    }
}

extension PHPObject: Equatable where T: Equatable {}
extension PHPObject: Hashable where T: Hashable {}
extension PHPMap: Equatable where Value: Equatable {}
extension PHPMap: Hashable where Value: Hashable {}

/// 通用 Laravel 分页对象
struct LaravelPaginator<T: Decodable>: Decodable {
    let currentPage: Int?
    let data: [T]
    let lastPage: Int?
    let perPage: Int?
    let total: Int?

    enum CodingKeys: String, CodingKey {
        case currentPage = "current_page"
        case data
        case lastPage = "last_page"
        case perPage = "per_page"
        case total
    }
}
