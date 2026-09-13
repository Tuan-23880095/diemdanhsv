# Web-diem-danh

Hệ thống điểm danh sinh viên có kiểm chứng + xem điểm.
Trường ĐH Khoa học Tự nhiên, ĐHQG-HCM — Đinh Quốc Tuấn.

## Cấu trúc thư mục

```
Web-diem-danh/
├── index.html          Trang chủ
├── pages/              login, lecturer, student, attendance, grade, report
├── css/                main.css, dashboard.css, print.css
├── js/
│   ├── config/         config.js  (API URL, hằng số)
│   ├── models/         Student, Lecturer, Course, AcademicClass,
│   │                   ClassSession, Attendance, Grade, Complaint
│   ├── services/       APIService, AttendanceService, StudentService,
│   │                   ReportService
│   ├── controllers/    LecturerController, StudentController,
│   │                   AttendanceController
│   ├── views/          AttendanceView, GradeView, ReportView
│   └── app.js
├── gas/                Mã Google Apps Script (backend)
├── templates/          Template HTML cho báo cáo in
├── assets/             images/, logo/
└── docs/               Tài liệu thiết kế
```

## Tài liệu

- `docs/01-THIETKE-kien-truc.md` — nguồn chân lý cho mọi quyết định thiết kế.

Khi có quyết định mới: sửa tài liệu thiết kế trước, cập nhật nguồn trong
NotebookLM `web-diemdanh-xemdiem`, rồi mới sửa code.

## Notebook tri thức

NotebookLM: **web-diemdanh-xemdiem**
https://notebooklm.google.com/notebook/9bbd1bc4-90c8-4bb9-9b17-bbbf26c20906

## Mã tham chiếu (bản đang chạy, sẽ thay thế)

- `../diemdanh-main/` — trang điểm danh hiện tại
- `../XemDiem-main/` — trang xem điểm hiện tại

Cả hai còn nợ kỹ thuật, xem PHẦN H của tài liệu thiết kế.
