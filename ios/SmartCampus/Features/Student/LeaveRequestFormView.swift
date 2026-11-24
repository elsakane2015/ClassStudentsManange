import PhotosUI
import SwiftUI
import UIKit

/// 学生端动态请假申请页（完整支持动态请假类型、时段/自定义节次、住宿生夜自习请假、相机拍照与相册多图压缩上传）
struct LeaveRequestFormView: View {
    let user: User

    @State private var leaveTypes: [LeaveType] = []
    @State private var timeSlots: [TimeSlot] = []
    @State private var regularPeriods: [ClassPeriod] = []
    @State private var eveningPeriods: [ClassPeriod] = []
    @State private var eveningStatuses: [EveningStudyStatus] = []
    @State private var imageSettings = LeaveImageSettings(enabled: true, maxCount: 3, maxSizeMB: 5, allowedFormats: "jpg,jpeg,png")

    @State private var selectedTypeSlug: String = ""
    @State private var startDate: Date = Date()
    @State private var endDate: Date = Date()
    @State private var selectedTimeSlotId: Int?
    @State private var selectedPeriodIds: Set<Int> = []
    @State private var selectedOptionKey: String = ""
    @State private var selectedTime: Date = Date()
    @State private var customTextDetail: String = ""
    @State private var reason: String = ""

    // Evening study fields (boarding students)
    @State private var selectedEveningStatusId: Int?
    @State private var destination: String = ""

    // Uploaded images
    @State private var uploadedPaths: [String] = []
    @State private var pickerItems: [PhotosPickerItem] = []
    @State private var showCamera = false
    @State private var isUploadingImage = false

    @State private var isLoadingConfig = false
    @State private var isSubmitting = false
    @State private var errorMessage: String?
    @State private var successAlert = false

    private var isBoarding: Bool { user.student?.isBoardingFlag ?? false }
    private var studentGender: String? { user.student?.gender }

    private var requestableLeaveTypes: [LeaveType] {
        leaveTypes.filter { lt in
            guard lt.isActiveFlag && lt.isStudentRequestable else { return false }
            let restriction = lt.genderRestriction ?? "all"
            if restriction == "all" { return true }
            return restriction == studentGender
        }
    }

    private var selectedLeaveType: LeaveType? {
        requestableLeaveTypes.first { $0.slug == selectedTypeSlug }
    }

    private var allSelectablePeriods: [ClassPeriod] {
        let regulars = regularPeriods.filter { !$0.isBoardingOnly || isBoarding }
        let evenings = isBoarding ? eveningPeriods : []
        return regulars + evenings
    }

    private var hasSelectedEveningPeriod: Bool {
        let eveningIds = Set(eveningPeriods.map(\.id))
        return !selectedPeriodIds.intersection(eveningIds).isEmpty
    }

    var body: some View {
        NavigationStack {
            Form {
                if let errorMessage {
                    Section {
                        ErrorBannerView(message: errorMessage)
                    }
                }

                // 1. Leave Type
                Section("请假类型") {
                    if isLoadingConfig && requestableLeaveTypes.isEmpty {
                        ProgressView("加载请假类型...")
                    } else if requestableLeaveTypes.isEmpty {
                        Text("当前没有可由学生在线申请的请假类型")
                            .foregroundStyle(.secondary)
                    } else {
                        Picker("选择类型", selection: $selectedTypeSlug) {
                            ForEach(requestableLeaveTypes) { lt in
                                Text(lt.name).tag(lt.slug)
                            }
                        }
                        .pickerStyle(.menu)

                        if let lt = selectedLeaveType {
                            if let desc = lt.description, !desc.isEmpty {
                                Text(desc)
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                            if let days = lt.limitDays?.value, let times = lt.limitTimes?.value, days > 0, times > 0 {
                                Label("频率限制：\(days) 天内最多申请 \(times) 次", systemImage: "info.circle")
                                    .font(.caption)
                                    .foregroundStyle(.orange)
                            }
                        }
                    }
                }

                // 2. Date Range
                Section("请假日期") {
                    DatePicker("开始日期", selection: $startDate, displayedComponents: .date)
                    DatePicker("结束日期", selection: $endDate, in: startDate..., displayedComponents: .date)
                }

                // 3. Dynamic Input based on leave type
                if let lt = selectedLeaveType {
                    dynamicConfigSection(for: lt)
                }

                // 4. Time Slots & Periods
                if !timeSlots.isEmpty || !allSelectablePeriods.isEmpty {
                    Section {
                        if !timeSlots.isEmpty {
                            VStack(alignment: .leading, spacing: 8) {
                                Text("快捷时段")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                                ScrollView(.horizontal, showsIndicators: false) {
                                    HStack(spacing: 8) {
                                        ForEach(timeSlots) { slot in
                                            let isSelected = selectedTimeSlotId == slot.id
                                            Button {
                                                selectTimeSlot(slot)
                                            } label: {
                                                Text(slot.name)
                                                    .font(.subheadline.weight(.medium))
                                                    .padding(.horizontal, 12)
                                                    .padding(.vertical, 7)
                                                    .background(isSelected ? SCTheme.primary : Color(.tertiarySystemFill))
                                                    .foregroundStyle(isSelected ? .white : .primary)
                                                    .clipShape(Capsule())
                                            }
                                            .buttonStyle(.plain)
                                        }
                                    }
                                }
                            }
                            .padding(.vertical, 4)
                        }

                        if !allSelectablePeriods.isEmpty {
                            VStack(alignment: .leading, spacing: 10) {
                                HStack {
                                    Text("具体节次（可多选微调）")
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                    Spacer()
                                    if !selectedPeriodIds.isEmpty {
                                        Button("清空") {
                                            selectedPeriodIds.removeAll()
                                            selectedTimeSlotId = nil
                                        }
                                        .font(.caption)
                                    }
                                }

                                LazyVGrid(columns: [GridItem(.adaptive(minimum: 76), spacing: 8)], spacing: 8) {
                                    ForEach(allSelectablePeriods) { period in
                                        let isOn = selectedPeriodIds.contains(period.id)
                                        Button {
                                            togglePeriod(period.id)
                                        } label: {
                                            Text(period.name)
                                                .font(.caption.weight(.medium))
                                                .lineLimit(1)
                                                .frame(maxWidth: .infinity)
                                                .padding(.vertical, 8)
                                                .padding(.horizontal, 6)
                                                .background(
                                                    isOn
                                                        ? (period.isEveningStudy ? Color.indigo : SCTheme.primary)
                                                        : Color(.tertiarySystemFill)
                                                )
                                                .foregroundStyle(isOn ? .white : .primary)
                                                .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
                                        }
                                        .buttonStyle(.plain)
                                    }
                                }
                            }
                            .padding(.vertical, 4)
                        }
                    } header: {
                        Text("时段与节次")
                    } footer: {
                        if !selectedPeriodIds.isEmpty {
                            Text("已选：" + PeriodFormatter.formatPeriodNames(
                                periodIds: Array(selectedPeriodIds),
                                periods: allSelectablePeriods,
                                timeSlots: timeSlots
                            ))
                        } else {
                            Text("若未选择具体节次，将默认按全天请假提交。")
                        }
                    }
                }

                // 5. Evening Study Extra Requirements (Boarding students)
                if hasSelectedEveningPeriod {
                    Section("夜自习去向信息（必填）") {
                        Picker("申请状态", selection: $selectedEveningStatusId) {
                            Text("请选择").tag(Int?.none)
                            ForEach(eveningStatuses) { st in
                                Text(st.name).tag(Int?.some(st.id))
                            }
                        }
                        TextField("请填写夜自习期间具体去向（如：回家、社团活动等）", text: $destination)
                    }
                }

                // 6. Reason
                Section("请假事由") {
                    TextField("请简要说明请假原因...", text: $reason, axis: .vertical)
                        .lineLimit(3...6)
                }

                // 7. Proof Images
                if imageSettings.enabled {
                    Section {
                        if !uploadedPaths.isEmpty {
                            ScrollView(.horizontal, showsIndicators: false) {
                                HStack(spacing: 12) {
                                    ForEach(uploadedPaths, id: \.self) { path in
                                        ZStack(alignment: .topTrailing) {
                                            RemoteMediaImage(pathOrURL: path, height: 84)
                                                .frame(width: 84, height: 84)
                                                .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))

                                            Button {
                                                Task { await removeImage(path) }
                                            } label: {
                                                Image(systemName: "xmark.circle.fill")
                                                    .foregroundStyle(.white, .red)
                                                    .font(.title3)
                                            }
                                            .offset(x: 6, y: -6)
                                        }
                                    }
                                }
                                .padding(.vertical, 6)
                            }
                        }

                        if uploadedPaths.count < imageSettings.maxCount {
                            HStack(spacing: 12) {
                                PhotosPicker(
                                    selection: $pickerItems,
                                    maxSelectionCount: max(1, imageSettings.maxCount - uploadedPaths.count),
                                    matching: .images
                                ) {
                                    Label("从相册选择", systemImage: "photo.on.rectangle")
                                        .frame(maxWidth: .infinity)
                                }
                                .buttonStyle(.bordered)
                                .disabled(isUploadingImage)

                                if UIImagePickerController.isSourceTypeAvailable(.camera) {
                                    Button {
                                        showCamera = true
                                    } label: {
                                        Label("现场拍照", systemImage: "camera")
                                            .frame(maxWidth: .infinity)
                                    }
                                    .buttonStyle(.bordered)
                                    .disabled(isUploadingImage)
                                }
                            }
                        }

                        if isUploadingImage {
                            HStack(spacing: 8) {
                                ProgressView()
                                Text("正在压缩并上传图片...")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }
                    } header: {
                        Text("证明材料（最多 \(imageSettings.maxCount) 张）")
                    } footer: {
                        Text("支持病历单、假条等图片上传，单张上限 \(imageSettings.maxSizeMB)MB，系统将自动压缩。")
                    }
                }

                // 8. Submit Button
                Section {
                    Button {
                        Task { await submitLeave() }
                    } label: {
                        HStack {
                            Spacer()
                            if isSubmitting { ProgressView().tint(.white) }
                            Text(isSubmitting ? "正在提交..." : "提交请假申请")
                                .font(.headline)
                            Spacer()
                        }
                        .padding(.vertical, 6)
                    }
                    .listRowBackground(SCTheme.primary)
                    .foregroundStyle(.white)
                    .disabled(isSubmitting || isUploadingImage || selectedTypeSlug.isEmpty)
                }
            }
            .navigationTitle("请假申请")
            .onChange(of: startDate) { _, newStart in
                if endDate < newStart {
                    endDate = newStart
                }
            }
            .onChange(of: pickerItems) { _, newItems in
                guard !newItems.isEmpty else { return }
                Task { await handlePickedPhotos(newItems) }
            }
            .sheet(isPresented: $showCamera) {
                CameraImagePicker { image in
                    Task { await uploadUIImage(image) }
                }
            }
            .alert("请假申请已提交", isPresented: $successAlert) {
                Button("好的") { resetForm() }
            } message: {
                Text("申请已发送给班主任及班级管理员，审批结果将通过通知提醒您。")
            }
            .task {
                await loadInitialConfig()
            }
        }
    }

    @ViewBuilder
    private func dynamicConfigSection(for lt: LeaveType) -> some View {
        switch lt.inputType {
        case "duration_select":
            if !lt.options.isEmpty {
                Section("时长选项") {
                    Picker("选择时长", selection: $selectedOptionKey) {
                        Text("按节次/默认").tag("")
                        ForEach(lt.options) { opt in
                            Text(opt.label).tag(opt.key)
                        }
                    }
                }
            }
        case "time":
            Section("具体时间") {
                DatePicker("选择时间", selection: $selectedTime, displayedComponents: .hourAndMinute)
            }
        case "text":
            Section("补充说明") {
                TextField(lt.inputConfig?.value?.placeholder ?? "请输入详细信息", text: $customTextDetail)
            }
        default:
            EmptyView()
        }
    }

    private func selectTimeSlot(_ slot: TimeSlot) {
        if selectedTimeSlotId == slot.id {
            selectedTimeSlotId = nil
            selectedPeriodIds.removeAll()
        } else {
            selectedTimeSlotId = slot.id
            let allowedIds = Set(allSelectablePeriods.map(\.id))
            selectedPeriodIds = Set((slot.periodIds ?? []).filter { allowedIds.contains($0) })
        }
    }

    private func togglePeriod(_ id: Int) {
        if selectedPeriodIds.contains(id) {
            selectedPeriodIds.remove(id)
        } else {
            selectedPeriodIds.insert(id)
        }
    }

    private func loadInitialConfig() async {
        isLoadingConfig = true
        errorMessage = nil
        defer { isLoadingConfig = false }

        do {
            async let typesReq = AttendanceRepository.fetchLeaveTypes()
            async let slotsReq = AttendanceRepository.fetchTimeSlots()
            async let periodsReq = AttendanceRepository.fetchClassPeriods()
            async let imgSettingsReq = LeaveRepository.fetchImageSettings()

            leaveTypes = try await typesReq
            timeSlots = (try? await slotsReq) ?? []
            regularPeriods = (try? await periodsReq) ?? []
            if let settings = try? await imgSettingsReq {
                imageSettings = settings
            }

            if isBoarding {
                eveningPeriods = (try? await EveningStudyRepository.fetchPeriods()) ?? []
                if let esRes = try? await EveningStudyRepository.fetchStatuses(studentRequestableOnly: true) {
                    eveningStatuses = esRes.statuses.filter { $0.isActiveFlag && $0.isStudentRequestableFlag }
                    if selectedEveningStatusId == nil {
                        selectedEveningStatusId = eveningStatuses.first?.id
                    }
                }
            }

            if selectedTypeSlug.isEmpty, let first = requestableLeaveTypes.first {
                selectedTypeSlug = first.slug
            }
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func handlePickedPhotos(_ items: [PhotosPickerItem]) async {
        defer { pickerItems = [] }
        for item in items {
            if uploadedPaths.count >= imageSettings.maxCount { break }
            if let data = try? await item.loadTransferable(type: Data.self),
               let image = UIImage(data: data) {
                await uploadUIImage(image)
            }
        }
    }

    private func uploadUIImage(_ image: UIImage) async {
        guard uploadedPaths.count < imageSettings.maxCount else { return }
        isUploadingImage = true
        errorMessage = nil
        defer { isUploadingImage = false }

        do {
            let res = try await LeaveRepository.uploadLeaveImage(image, maxSizeMB: imageSettings.maxSizeMB)
            uploadedPaths.append(res.path)
        } catch {
            errorMessage = "图片上传失败：\(error.localizedDescription)"
        }
    }

    private func removeImage(_ path: String) async {
        uploadedPaths.removeAll { $0 == path }
        try? await LeaveRepository.deleteUploadedImage(path: path)
    }

    private func submitLeave() async {
        errorMessage = nil

        if hasSelectedEveningPeriod {
            if selectedEveningStatusId == nil {
                errorMessage = "请选择夜自习申请状态"
                return
            }
            if destination.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                errorMessage = "请填写夜自习期间的具体去向"
                return
            }
        }

        isSubmitting = true
        defer { isSubmitting = false }

        let trimmedReason = reason.trimmingCharacters(in: .whitespacesAndNewlines)
        let trimmedCustomText = customTextDetail.trimmingCharacters(in: .whitespacesAndNewlines)
        let effectiveReason = trimmedReason.isEmpty ? trimmedCustomText : trimmedReason

        var payload: [String: Any] = [
            "type": selectedTypeSlug,
            "start_date": SCDateFormatter.yyyyMMdd.string(from: startDate),
            "end_date": SCDateFormatter.yyyyMMdd.string(from: endDate),
            "reason": effectiveReason
        ]

        if let slotId = selectedTimeSlotId {
            payload["time_slot_id"] = slotId
        }
        if !selectedPeriodIds.isEmpty {
            payload["sessions"] = Array(selectedPeriodIds).sorted()
        }
        if !uploadedPaths.isEmpty {
            payload["images"] = uploadedPaths
        }

        var details: [String: Any] = [:]
        if !selectedOptionKey.isEmpty {
            details["option"] = selectedOptionKey
            if selectedOptionKey == "am" || selectedOptionKey == "pm" {
                payload["half_day"] = selectedOptionKey
            }
        }
        if selectedLeaveType?.inputType == "time" {
            details["time"] = SCDateFormatter.hhmm.string(from: selectedTime)
        }
        if selectedLeaveType?.inputType == "text" {
            let effectiveDetailText = trimmedCustomText.isEmpty ? trimmedReason : trimmedCustomText
            if !effectiveDetailText.isEmpty {
                details["text"] = effectiveDetailText
            }
        }
        if !details.isEmpty {
            payload["details"] = details
        }

        if hasSelectedEveningPeriod {
            payload["evening_study_status_id"] = selectedEveningStatusId
            payload["destination"] = destination.trimmingCharacters(in: .whitespacesAndNewlines)
        }

        do {
            try await LeaveRepository.submitLeaveRequest(payload: payload)
            successAlert = true
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func resetForm() {
        selectedTimeSlotId = nil
        selectedPeriodIds.removeAll()
        selectedOptionKey = ""
        customTextDetail = ""
        reason = ""
        destination = ""
        uploadedPaths.removeAll()
    }
}

/// 封装原生 UIImagePickerController 以支持现场拍照上传请假凭证
struct CameraImagePicker: UIViewControllerRepresentable {
    var onImagePicked: (UIImage) -> Void
    @Environment(\.dismiss) private var dismiss

    func makeCoordinator() -> Coordinator { Coordinator(self) }

    func makeUIViewController(context: Context) -> UIImagePickerController {
        let picker = UIImagePickerController()
        picker.sourceType = .camera
        picker.delegate = context.coordinator
        return picker
    }

    func updateUIViewController(_ uiViewController: UIImagePickerController, context: Context) {}

    final class Coordinator: NSObject, UINavigationControllerDelegate, UIImagePickerControllerDelegate {
        let parent: CameraImagePicker
        init(_ parent: CameraImagePicker) { self.parent = parent }

        func imagePickerController(
            _ picker: UIImagePickerController,
            didFinishPickingMediaWithInfo info: [UIImagePickerController.InfoKey: Any]
        ) {
            if let image = info[.originalImage] as? UIImage {
                parent.onImagePicked(image)
            }
            parent.dismiss()
        }

        func imagePickerControllerDidCancel(_ picker: UIImagePickerController) {
            parent.dismiss()
        }
    }
}
