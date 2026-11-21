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
