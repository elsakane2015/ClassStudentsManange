import SwiftUI

/// 班级考勤员（is_class_admin）专属：快速维护本班当日考勤
struct ClassAttendanceAdminView: View {
    let user: User

    @State private var selectedDate: Date = Date()
    @State private var students: [AttendanceOverviewStudent] = []
    @State private var statusDrafts: [Int: String] = [:]
    @State private var isLoading = false
    @State private var isSaving = false
    @State private var errorMessage: String?
    @State private var saveSuccess = false

    private let statusChoices: [(key: String, label: String, color: Color)] = [
        ("present", "出勤", .green),
        ("late", "迟到", .orange),
        ("absent", "旷课", .red),
        ("leave", "请假", .blue),
        ("early_leave", "早退", .purple)
    ]

    var body: some View {
        VStack(spacing: 0) {
            HStack {
                DatePicker("考勤日期", selection: $selectedDate, displayedComponents: .date)
                Spacer()
                Button("全员出勤") {
                    for s in students { statusDrafts[s.id] = "present" }
                }
                .font(.caption.weight(.semibold))
                .buttonStyle(.bordered)
            }
            .padding()
            .background(SCTheme.cardBackground)

            if let errorMessage {
                ErrorBannerView(message: errorMessage) {
                    Task { await loadClassOverview() }
                }
                .padding()
            }

            if isLoading && students.isEmpty {
                ProgressView("加载本班学生名单...")
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
            } else {
                List(students) { student in
                    VStack(alignment: .leading, spacing: 8) {
                        HStack {
                            Text(student.displayName)
                                .font(.subheadline.weight(.semibold))
                            if let no = student.studentNo {
                                Text(no)
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                            Spacer()
                        }

                        HStack(spacing: 6) {
                            ForEach(statusChoices, id: \.key) { choice in
                                let current = statusDrafts[student.id] ?? "present"
                                let selected = current == choice.key
                                Button {
                                    statusDrafts[student.id] = choice.key
                                } label: {
                                    Text(choice.label)
                                        .font(.caption.weight(.medium))
                                        .frame(maxWidth: .infinity)
                                        .padding(.vertical, 6)
                                        .background(selected ? choice.color : Color(.tertiarySystemFill))
                                        .foregroundStyle(selected ? .white : .primary)
                                        .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }
                    .padding(.vertical, 4)
                }
                .listStyle(.insetGrouped)
            }
        }
        .navigationTitle("班级考勤维护")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .confirmationAction) {
                Button {
                    Task { await saveBatch() }
                } label: {
                    if isSaving { ProgressView() } else { Text("保存").bold() }
                }
                .disabled(isSaving || students.isEmpty)
            }
        }
        .onChange(of: selectedDate) { _, _ in
            Task { await loadClassOverview() }
        }
        .alert("考勤已保存", isPresented: $saveSuccess) {
            Button("好的") {}
        }
        .task {
            await loadClassOverview()
        }
    }

    private func loadClassOverview() async {
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }

        do {
            let dateStr = SCDateFormatter.yyyyMMdd.string(from: selectedDate)
            let overview = try await AttendanceRepository.fetchAttendanceOverview(date: dateStr)
            let allStudents = overview
                .flatMap { $0.classes ?? [] }
                .flatMap { $0.students ?? [] }
            students = allStudents

            var drafts: [Int: String] = [:]
            for s in allStudents {
                drafts[s.id] = s.attendance?.first?.status ?? "present"
            }
            statusDrafts = drafts
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func saveBatch() async {
        isSaving = true
        errorMessage = nil
        defer { isSaving = false }

        let dateStr = SCDateFormatter.yyyyMMdd.string(from: selectedDate)
        let records: [[String: Any]] = students.map { s in
            [
                "student_id": s.id,
                "date": dateStr,
                "status": statusDrafts[s.id] ?? "present"
            ]
        }

        do {
            try await AttendanceRepository.submitBatchAttendance(
                classId: user.student?.classId,
                records: records
            )
            saveSuccess = true
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}
