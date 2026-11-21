import Foundation
import SwiftData

/// SwiftData 离线点名草稿记录：教室/宿舍信号不佳或杀进程重开后，恢复本地已标记的出勤状态
@Model
final class RollCallDraftRecord {
    @Attribute(.unique) var compositeKey: String
    var rollCallId: Int
    var studentId: Int
    var status: String
    var markedAtISO: String?
    var markedAtLocal: String?
    var updatedAt: Date

    init(
        rollCallId: Int,
        studentId: Int,
        status: String,
        markedAtISO: String?,
        markedAtLocal: String?
    ) {
        self.compositeKey = "\(rollCallId)_\(studentId)"
        self.rollCallId = rollCallId
        self.studentId = studentId
        self.status = status
        self.markedAtISO = markedAtISO
        self.markedAtLocal = markedAtLocal
        self.updatedAt = Date()
    }
}

@MainActor
final class OfflineRollCallStore {
    static let shared = OfflineRollCallStore()

    let container: ModelContainer?

    private init() {
        do {
            let schema = Schema([RollCallDraftRecord.self])
            let config = ModelConfiguration(schema: schema, isStoredInMemoryOnly: false)
            self.container = try ModelContainer(for: schema, configurations: [config])
        } catch {
            self.container = nil
        }
    }

    func saveMark(
        rollCallId: Int,
        studentId: Int,
        status: String,
        markedAtISO: String?,
        markedAtLocal: String?
    ) {
        guard let context = container?.mainContext else { return }
        let key = "\(rollCallId)_\(studentId)"
        let descriptor = FetchDescriptor<RollCallDraftRecord>(
            predicate: #Predicate { $0.compositeKey == key }
        )

        if let existing = try? context.fetch(descriptor).first {
            existing.status = status
            existing.markedAtISO = markedAtISO
            existing.markedAtLocal = markedAtLocal
            existing.updatedAt = Date()
        } else {
            let draft = RollCallDraftRecord(
                rollCallId: rollCallId,
                studentId: studentId,
                status: status,
                markedAtISO: markedAtISO,
                markedAtLocal: markedAtLocal
            )
            context.insert(draft)
        }
        try? context.save()
    }

    func loadDrafts(for rollCallId: Int) -> [Int: RollCallDraftRecord] {
        guard let context = container?.mainContext else { return [:] }
        let descriptor = FetchDescriptor<RollCallDraftRecord>(
            predicate: #Predicate { $0.rollCallId == rollCallId }
        )
        guard let items = try? context.fetch(descriptor) else { return [:] }
        return Dictionary(uniqueKeysWithValues: items.map { ($0.studentId, $0) })
    }

    func clearDrafts(for rollCallId: Int) {
        guard let context = container?.mainContext else { return }
        let descriptor = FetchDescriptor<RollCallDraftRecord>(
            predicate: #Predicate { $0.rollCallId == rollCallId }
        )
        if let items = try? context.fetch(descriptor) {
            for item in items {
                context.delete(item)
            }
            try? context.save()
        }
    }
}
