import Foundation
import UIKit

enum LeaveRepository {
    static func fetchLeaveRequests(
        status: String? = nil,
        dateFrom: String? = nil,
        dateTo: String? = nil
    ) async throws -> [LeaveRequestGroup] {
        var query: [String: String?] = [:]
        if let status, !status.isEmpty, status != "all" { query["status"] = status }
        if let dateFrom { query["date_from"] = dateFrom }
        if let dateTo { query["date_to"] = dateTo }

        let response: LeaveRequestsListResponse = try await APIClient.shared.request(
            "/leave-requests",
            query: query
        )
        return response.data
    }

    static func fetchLeaveRequestsPage(
        status: String? = nil,
        dateFrom: String? = nil,
        dateTo: String? = nil,
        search: String? = nil,
        page: Int = 1,
        perPage: Int = 20
    ) async throws -> LeaveRequestsPageResult {
        var query: [String: String?] = [
            "page": String(max(1, page)),
            "per_page": String(max(1, perPage))
        ]
        if let status, !status.isEmpty, status != "all" { query["status"] = status }
        if let dateFrom, !dateFrom.isEmpty { query["date_from"] = dateFrom }
        if let dateTo, !dateTo.isEmpty { query["date_to"] = dateTo }
        let trimmedSearch = search?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        if !trimmedSearch.isEmpty { query["search"] = trimmedSearch }

        let response: LeaveRequestsListResponse = try await APIClient.shared.request(
            "/leave-requests",
            query: query
        )

        if let meta = response.meta {
            return LeaveRequestsPageResult(
                items: response.data,
                currentPage: meta.currentPage,
                lastPage: meta.lastPage,
                total: meta.total,
                hasMore: meta.hasMore,
                serverPaginated: true
            )
        }

        // 兼容尚未升级分页字段的旧服务端：若传了 search 在本地兜底过滤，且不再循环翻页
        let fallbackItems: [LeaveRequestGroup]
        if trimmedSearch.isEmpty {
            fallbackItems = response.data
        } else {
            let q = trimmedSearch.lowercased()
            fallbackItems = response.data.filter { r in
                (r.student?.name ?? "").lowercased().contains(q)
                    || (r.student?.studentNo ?? "").lowercased().contains(q)
                    || (r.schoolClass?.name ?? "").lowercased().contains(q)
            }
        }
        return LeaveRequestsPageResult(
            items: fallbackItems,
            currentPage: 1,
            lastPage: 1,
            total: fallbackItems.count,
            hasMore: false,
            serverPaginated: false
        )
    }

    static func fetchImageSettings() async throws -> LeaveImageSettings {
        try await APIClient.shared.request("/leave-image/settings")
    }

    static func uploadLeaveImage(_ image: UIImage, maxSizeMB: Int) async throws -> LeaveImageUploadResponse {
        try await APIClient.shared.uploadImage("/leave-image/upload", image: image, maxSizeMB: maxSizeMB)
    }

    static func deleteUploadedImage(path: String) async throws {
        let _: MessageResponse = try await APIClient.shared.requestWithJSONBody(
            "/leave-image",
            method: "DELETE",
            jsonBody: ["path": path]
        )
    }

    static func submitLeaveRequest(payload: [String: Any]) async throws {
        let _: EmptyResponse = try await APIClient.shared.requestWithJSONBody(
            "/leave-requests",
            method: "POST",
            jsonBody: payload
        )
    }

    static func cancelLeaveRequest(id: Int) async throws {
        let _: MessageResponse = try await APIClient.shared.request(
            "/leave-requests/\(id)",
            method: "DELETE"
        )
    }

    static func approveLeaveRequest(
        id: Int,
        recordIds: [Int]? = nil,
        leaveTypeId: Int? = nil
    ) async throws {
        var body: [String: Any] = [:]
        if let recordIds, !recordIds.isEmpty { body["record_ids"] = recordIds }
        if let leaveTypeId { body["leave_type_id"] = leaveTypeId }
        let _: MessageResponse = try await APIClient.shared.requestWithJSONBody(
            "/leave-requests/\(id)/approve",
            method: "POST",
            jsonBody: body
        )
    }

    static func rejectLeaveRequest(
        id: Int,
        recordIds: [Int]? = nil,
        reason: String? = nil
    ) async throws {
        var body: [String: Any] = [:]
        if let recordIds, !recordIds.isEmpty { body["record_ids"] = recordIds }
        if let reason, !reason.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            body["reason"] = reason
        }
        let _: MessageResponse = try await APIClient.shared.requestWithJSONBody(
            "/leave-requests/\(id)/reject",
            method: "POST",
            jsonBody: body
        )
    }
}
