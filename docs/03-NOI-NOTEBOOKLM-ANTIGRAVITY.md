# 03 — Nối NotebookLM MCP vào Antigravity

Claude Desktop đã nối xong (12/09/2026). Antigravity thì **chưa** — cần thêm tay.

Cùng một server, cùng một lần đăng nhập, phục vụ cả hai công cụ.

## Tạo file cấu hình

Đường dẫn (Windows):

```
C:\Users\Quoc_\.gemini\config\mcp_config.json
```

Nếu file chưa có thì tạo mới. Nếu đã có `mcpServers` thì chỉ thêm khoá
`gemini-notebook-mcp` vào trong, đừng ghi đè cả file.

```json
{
  "mcpServers": {
    "gemini-notebook-mcp": {
      "command": "C:\\Users\\Quoc_\\.local\\bin\\notebooklm-mcp.exe",
      "disabledTools": []
    }
  }
}
```

Muốn cấu hình riêng cho dự án này thì đặt cùng nội dung tại
`D:\2026-Web-DayKemToan\Web-diem-danh\.agents\mcp_config.json`.

## Khác biệt so với Claude — dễ mất thời gian nếu không biết

- Antigravity dùng **`mcp_config.json`**, không phải `claude_desktop_config.json`.
- Server từ xa dùng khoá **`serverUrl`**; Antigravity **không** chấp nhận
  `url` hay `httpUrl`. (Trường hợp này dùng `command` nên không ảnh hưởng.)
- Có **`disabledTools`** — server này cung cấp 48 tool, bật hết thì model
  "ngộp" và tốn context. Với dự án này chỉ cần: `notebook_list`,
  `notebook_query`, `source_add`, `source_get_content`. Liệt kê phần còn lại
  vào `disabledTools` nếu thấy chậm.
- Tool MCP mặc định ở chế độ **Ask** — phải duyệt từng lời gọi.
- MCP Store của Antigravity **không có** NotebookLM server, luôn phải thêm tay.

## Kiểm tra

Mở Antigravity, gõ `/mcp` trong CLI (hoặc nút `…` trên agent panel →
**MCP Servers**) để xem trạng thái kết nối. Rồi thử:

> Liệt kê các notebook NotebookLM của tôi.

Phải thấy notebook **web-diemdanh-xemdiem**.

## Về model và quota

Antigravity có sẵn Claude Sonnet và Claude Opus trong dropdown chọn model,
và quota của nó **tách riêng** với quota của Claude — hai rổ khác nhau. Nên
khi phiên Claude hết hạn mức, Antigravity vẫn chạy được.

Kiểm tra lại danh sách model trong dropdown của bản Antigravity đang cài,
vì Google thay đổi theo thời gian và theo gói tài khoản.

**Không** dùng proxy để lấy quota Claude của Antigravity đưa ngược sang
Claude Code. Đó là dùng sai mục đích quota và có rủi ro khoá tài khoản.
