<?php
declare(strict_types=1);

/**
 * tvts02/kb.php — Bộ tri thức & khung phân tích cho TVTS02.
 *
 * Nội dung hướng dẫn diễn giải được rút từ notebook NotebookLM "huong nghiep" (186 nguồn) —
 * chủ yếu: Savickas (2005, 2021) Career Construction / My Career Story; Holland (1997)
 * Making Vocational Choices & Self-Directed Search (nghề mơ ước); Lent, Brown & Hackett (1994, 2000)
 * SCCT; Bandura (1977, 1997); Larson, Rottinghaus & Borgen (2002); Leong (2000, 2002),
 * Leung et al. (2011), Sawitri et al. (2013) về bối cảnh gia đình châu Á.
 * 30 câu sở thích: O*NET Mini Interest Profiler (Rounds et al., 2016), bản dịch tiếng Việt.
 */

const TVTS_ORDER = ['R', 'I', 'A', 'S', 'E', 'C'];
const TVTS_TYPE_NAMES = ['R' => 'Kỹ thuật', 'I' => 'Nghiên cứu', 'A' => 'Nghệ thuật', 'S' => 'Xã hội', 'E' => 'Quản lý', 'C' => 'Nghiệp vụ'];

const TVTS_RIASEC_ITEMS = [
    ['R', 'Đóng tủ bếp, kệ gỗ'], ['I', 'Nghiên cứu phát triển một loại thuốc mới'], ['A', 'Viết sách hoặc kịch bản sân khấu'],
    ['S', 'Giúp người khác vượt qua khó khăn cá nhân hay cảm xúc'], ['E', 'Quản lý một phòng ban trong công ty lớn'], ['C', 'Cài đặt phần mềm cho các máy tính trong một hệ thống mạng lớn'],
    ['R', 'Sửa chữa đồ điện gia dụng'], ['I', 'Tìm cách giảm ô nhiễm nguồn nước'], ['A', 'Sáng tác hoặc phối khí âm nhạc'],
    ['S', 'Tư vấn nghề nghiệp cho người khác'], ['E', 'Tự khởi nghiệp kinh doanh'], ['C', 'Dùng máy tính cầm tay để tính toán số liệu'],
    ['R', 'Lắp ráp linh kiện điện tử'], ['I', 'Làm thí nghiệm hoá học'], ['A', 'Làm kỹ xảo cho phim'],
    ['S', 'Hướng dẫn người bệnh tập phục hồi chức năng'], ['E', 'Đàm phán hợp đồng kinh doanh'], ['C', 'Ghi chép sổ sách hàng nhập, hàng xuất'],
    ['R', 'Lái xe tải giao hàng đến văn phòng và nhà dân'], ['I', 'Quan sát mẫu máu dưới kính hiển vi'], ['A', 'Vẽ phông cảnh cho vở kịch'],
    ['S', 'Làm tình nguyện cho một tổ chức phi lợi nhuận'], ['E', 'Tiếp thị (marketing) một dòng quần áo mới'], ['C', 'Kiểm kê vật tư bằng máy quét cầm tay'],
    ['R', 'Kiểm tra chất lượng linh kiện trước khi xuất xưởng'], ['I', 'Tìm cách dự báo thời tiết chính xác hơn'], ['A', 'Viết kịch bản phim hoặc chương trình truyền hình'],
    ['S', 'Dạy một lớp học trung học phổ thông'], ['E', 'Bán hàng ở trung tâm thương mại'], ['C', 'Đóng dấu, phân loại và chuyển công văn, thư từ cho một cơ quan'],
];

/** 10 câu tự luận: q = câu hỏi, y = ý nghĩa (để phân tích), c = cơ sở. Đồng bộ với TVTS02.html. */
const TVTS_ESSAYS = [
    'q1'  => ['q' => 'Kể 3 người em ngưỡng mộ (người thật hoặc nhân vật). Ở mỗi người, em quý điều gì nhất?', 'y' => 'Hình mẫu → khái niệm bản thân lý tưởng, phẩm chất em đang tự xây dựng', 'c' => 'Savickas (2005, 2021)'],
    'q2'  => ['q' => 'Những kênh, trang, sách hay chương trình nào em theo dõi đều đặn? Điều gì ở đó cuốn hút em?', 'y' => 'Môi trường ưa thích → quy về RIASEC', 'c' => 'Savickas; Holland (1997)'],
    'q3'  => ['q' => 'Câu chuyện, bộ phim hoặc cuốn sách em thích nhất là gì? Kể ngắn nội dung chính và điều em nhớ nhất.', 'y' => 'Kịch bản cuộc sống: nhân vật vượt thử thách ra sao', 'c' => 'Savickas'],
    'q4'  => ['q' => 'Câu nói, châm ngôn hoặc lời khuyên nào em hay tự nhắc mình? Vì sao?', 'y' => 'Lời khuyên cho chính mình khi gặp khó', 'c' => 'Savickas'],
    'q5'  => ['q' => 'Liệt kê tất cả những nghề em từng mơ ước hoặc từng nghĩ tới, từ nhỏ đến bây giờ (càng nhiều càng tốt).', 'y' => 'Nghề mơ ước → quy mã Holland từng nghề, tìm chữ cái lặp lại', 'c' => 'Holland (1997), Self-Directed Search'],
    'q6'  => ['q' => 'Kể một lần em làm một việc gì đó đến quên cả thời gian, hoặc một việc em thấy tự hào nhất. Em đã làm gì?', 'y' => 'Sở thích thật + trải nghiệm thành công (nguồn tự hiệu quả)', 'c' => 'Bandura (1997); Lent et al. (1994)'],
    'q7'  => ['q' => 'Môn học hoặc việc gì em làm tốt — theo em là nhờ đâu? Môn nào em thấy khó — vì sao?', 'y' => 'Tự hiệu quả, cách quy kết thành công/thất bại', 'c' => 'SCCT (Lent et al., 1994)'],
    'q8'  => ['q' => 'Hãy hình dung một ngày làm việc của em năm 30 tuổi: em làm ở đâu, với ai, làm những việc gì?', 'y' => 'Giá trị nghề, hình ảnh bản thân tương lai, kỳ vọng kết quả', 'c' => 'Super (1980); Taber & Blankemeyer (2015)'],
    'q9'  => ['q' => 'Gia đình mong em theo nghề hoặc ngành nào? Em nghĩ sao về mong muốn đó?', 'y' => 'Mức đồng thuận cha mẹ – con; hỗ trợ và áp lực', 'c' => 'Leong (2002); Sawitri et al. (2013); Leung et al. (2011)'],
    'q10' => ['q' => 'Khi nghĩ đến chuyện chọn ngành, điều gì làm em băn khoăn hoặc lo lắng nhất?', 'y' => 'Rào cản cảm nhận, mức sẵn sàng (quan tâm, tự chủ, tò mò, tự tin)', 'c' => 'SCCT (Lent et al., 2000); Savickas (2005)'],
];

/** Nhóm ngành (đồng bộ TVTS01): id => [tên, mã Holland gần đúng, môn then chốt, ví dụ ngành]. */
const TVTS_MAJORS = [
    1 => ['Cơ khí, cơ điện tử, công nghệ ô tô', 'RIC', 'Toán, Vật lí, Công nghệ', 'Kĩ thuật cơ khí, Cơ điện tử, Công nghệ kĩ thuật ô tô, Kĩ thuật nhiệt'],
    2 => ['Điện, điện tử, tự động hoá', 'RIC', 'Toán, Vật lí, Tin học', 'Kĩ thuật điện, Điện tử – viễn thông, Điều khiển và tự động hoá, Thiết kế vi mạch'],
    3 => ['Xây dựng, giao thông, thuỷ lợi', 'RCI', 'Toán, Vật lí', 'Kĩ thuật xây dựng, Xây dựng cầu đường, Quản lí xây dựng, Kĩ thuật tài nguyên nước'],
    4 => ['Kiến trúc, quy hoạch, thiết kế nội thất', 'ARI', 'Toán, Mĩ thuật, Vật lí', 'Kiến trúc, Quy hoạch vùng và đô thị, Thiết kế nội thất'],
    5 => ['Công nghệ thông tin, kĩ thuật phần mềm', 'IRC', 'Toán, Tin học, Tiếng Anh', 'Công nghệ thông tin, Kĩ thuật phần mềm, Khoa học máy tính, Mạng máy tính'],
    6 => ['Khoa học dữ liệu, trí tuệ nhân tạo', 'ICR', 'Toán, Tin học, Tiếng Anh', 'Khoa học dữ liệu, Trí tuệ nhân tạo, Toán tin, Thống kê'],
    7 => ['An toàn thông tin, hệ thống thông tin', 'CIR', 'Toán, Tin học', 'An toàn thông tin, Hệ thống thông tin quản lí'],
    8 => ['Khoa học tự nhiên cơ bản', 'IRA', 'Toán, Vật lí, Hoá học, Sinh học', 'Toán học, Vật lí học, Hoá học, Sinh học, Khoa học vật liệu, Hải dương học'],
    9 => ['Địa chất, môi trường, khoa học Trái Đất', 'IRS', 'Địa lí, Hoá học, Sinh học, Toán', 'Địa chất học, Kĩ thuật địa chất, Khoa học môi trường, Quản lí tài nguyên và môi trường, Khí tượng thuỷ văn'],
    10 => ['Kĩ thuật hoá học, vật liệu, thực phẩm', 'IRC', 'Hoá học, Toán, Sinh học', 'Kĩ thuật hoá học, Công nghệ thực phẩm, Công nghệ vật liệu'],
    11 => ['Công nghệ sinh học, nông nghiệp công nghệ cao', 'IRS', 'Sinh học, Hoá học, Toán', 'Công nghệ sinh học, Nông học, Bảo vệ thực vật, Thú y, Nuôi trồng thuỷ sản'],
    12 => ['Y khoa, răng hàm mặt, dược', 'ISR', 'Sinh học, Hoá học, Toán', 'Y khoa, Răng – Hàm – Mặt, Dược học, Y học cổ truyền'],
    13 => ['Điều dưỡng, kĩ thuật y học, phục hồi chức năng', 'SIR', 'Sinh học, Hoá học, Toán', 'Điều dưỡng, Hộ sinh, Kĩ thuật xét nghiệm y học, Kĩ thuật phục hồi chức năng, Dinh dưỡng'],
    14 => ['Tâm lí học, công tác xã hội', 'SIA', 'Ngữ văn, Sinh học, GD kinh tế và pháp luật', 'Tâm lí học, Tâm lí học giáo dục, Công tác xã hội'],
    15 => ['Sư phạm khối tự nhiên, tin học', 'SIC', 'Toán, Vật lí, Hoá học, Sinh học, Tin học', 'Sư phạm Toán, Vật lí, Hoá học, Sinh học, Tin học, Khoa học tự nhiên; Công nghệ giáo dục'],
    16 => ['Sư phạm khối xã hội, ngoại ngữ, tiểu học, mầm non', 'SAE', 'Ngữ văn, Lịch sử, Địa lí, Tiếng Anh', 'Sư phạm Ngữ văn, Lịch sử – Địa lí, Tiếng Anh, Giáo dục tiểu học, Giáo dục mầm non'],
    17 => ['Ngôn ngữ, biên – phiên dịch', 'ASE', 'Tiếng Anh, Ngữ văn', 'Ngôn ngữ Anh, Trung, Hàn, Nhật; Biên – phiên dịch'],
    18 => ['Báo chí, truyền thông, quan hệ công chúng', 'AES', 'Ngữ văn, Tiếng Anh, Lịch sử', 'Báo chí, Truyền thông đa phương tiện, Quan hệ công chúng, Xuất bản'],
    19 => ['Văn học, lịch sử, văn hoá học', 'AIS', 'Ngữ văn, Lịch sử, Địa lí', 'Văn học, Lịch sử, Văn hoá học, Việt Nam học, Quản lí văn hoá'],
    20 => ['Thiết kế đồ hoạ, thời trang, mĩ thuật', 'AER', 'Mĩ thuật, Tin học, Ngữ văn', 'Thiết kế đồ hoạ, Thiết kế thời trang, Hội hoạ, Thiết kế công nghiệp'],
    21 => ['Âm nhạc, sân khấu, điện ảnh, nhiếp ảnh', 'ASE', 'Âm nhạc, Ngữ văn, Mĩ thuật', 'Thanh nhạc, Biểu diễn nhạc cụ, Diễn viên, Đạo diễn, Quay phim, Nhiếp ảnh'],
    22 => ['Quản trị kinh doanh, marketing', 'ESC', 'Toán, Tiếng Anh, Ngữ văn', 'Quản trị kinh doanh, Marketing, Quản trị nhân lực, Kinh doanh thương mại'],
    23 => ['Kinh doanh quốc tế, thương mại điện tử', 'ECS', 'Toán, Tiếng Anh, Tin học', 'Kinh doanh quốc tế, Kinh tế đối ngoại, Thương mại điện tử'],
    24 => ['Khởi nghiệp, kinh doanh tự do', 'EAR', 'Toán, Ngữ văn, GD kinh tế và pháp luật', 'Quản trị khởi nghiệp; kinh doanh hộ gia đình; nông nghiệp – dịch vụ tự chủ'],
    25 => ['Tài chính, ngân hàng, bảo hiểm', 'CEI', 'Toán, Tiếng Anh, GD kinh tế và pháp luật', 'Tài chính – Ngân hàng, Bảo hiểm, Công nghệ tài chính'],
    26 => ['Kế toán, kiểm toán', 'CIE', 'Toán, Tin học', 'Kế toán, Kiểm toán'],
    27 => ['Kinh tế học, thống kê kinh tế, kinh tế đất đai', 'ICE', 'Toán, Tiếng Anh, Địa lí', 'Kinh tế học, Kinh tế phát triển, Thống kê kinh tế, Kinh tế đất đai'],
    28 => ['Logistics, quản lí chuỗi cung ứng', 'CER', 'Toán, Tiếng Anh, Địa lí', 'Logistics và quản lí chuỗi cung ứng, Khai thác vận tải'],
    29 => ['Luật', 'ESI', 'Ngữ văn, Lịch sử, GD kinh tế và pháp luật', 'Luật, Luật kinh tế, Luật quốc tế'],
    30 => ['Hành chính, quản lí nhà nước, lưu trữ', 'CSE', 'Ngữ văn, Lịch sử, GD kinh tế và pháp luật', 'Quản lí nhà nước, Hành chính học, Lưu trữ học, Quản trị văn phòng'],
    31 => ['Du lịch, khách sạn, nhà hàng', 'ESC', 'Tiếng Anh, Địa lí, Ngữ văn', 'Quản trị dịch vụ du lịch và lữ hành, Quản trị khách sạn, Quản trị nhà hàng'],
    32 => ['Hàng không, hàng hải, vận tải', 'RCE', 'Toán, Vật lí, Tiếng Anh', 'Điều khiển tàu biển, Khai thác máy tàu, Kĩ thuật hàng không'],
    33 => ['Thể dục thể thao, huấn luyện', 'RSE', 'Giáo dục thể chất, Sinh học', 'Giáo dục thể chất, Huấn luyện thể thao, Quản lí thể dục thể thao'],
    34 => ['Công an, quân đội', 'RSE', 'Toán, Ngữ văn, Giáo dục thể chất', 'Các trường công an, quân đội (cần sức khoẻ, sơ tuyển riêng)'],
    35 => ['Nghề kĩ thuật (cao đẳng, trung cấp)', 'RCI', 'Công nghệ, Toán, Vật lí', 'Điện công nghiệp, Hàn, Sửa chữa ô tô, Điện lạnh, Cắt gọt kim loại, Lắp đặt điện nước'],
    36 => ['Nghề dịch vụ – chăm sóc (cao đẳng, trung cấp)', 'SRE', 'Sinh học, Ngữ văn, Tiếng Anh', 'Điều dưỡng, Chăm sóc sắc đẹp, Kĩ thuật chế biến món ăn, Hướng dẫn du lịch'],
];

function tvts_majors_by_id(): array
{
    $o = [];
    foreach (TVTS_MAJORS as $id => $m) $o[$id] = ['n' => $m[0], 'c' => $m[1], 'sn' => $m[2], 'ex' => $m[3]];
    return $o;
}
function tvts_majors_for_prompt(): array
{
    $o = [];
    foreach (TVTS_MAJORS as $id => $m) $o[] = ['id' => $id, 'ten' => $m[0], 'ma' => $m[1], 'mon' => $m[2]];
    return $o;
}

/** Chỉ dẫn hệ thống: khung phân tích rút từ notebook + quy tắc viết bản tư vấn. */
function tvts_kb_system(): string
{
    return <<<'TXT'
Bạn là chuyên viên tư vấn hướng nghiệp học đường, viết bản phân tích cho một học sinh Việt Nam (THCS/THPT) dựa trên: (a) điểm 30 câu sở thích O*NET Mini Interest Profiler (6 nhóm Holland, mỗi nhóm 0–20) và (b) 10 câu tự luận của học sinh. Viết tiếng Việt chuẩn, xưng "em" với học sinh, giọng ấm áp, cụ thể, tôn trọng, như một bản tư vấn chuyên môn. Trả về đúng JSON theo lược đồ.

KHUNG DIỄN GIẢI (từ tài liệu khoa học):
1. Savickas – Kiến tạo nghề nghiệp (Career Construction Interview, My Career Story):
 - Hình mẫu ngưỡng mộ (q1) cho biết khái niệm bản thân lý tưởng: chú ý các PHẨM CHẤT em gán cho nhân vật (dũng cảm, kiên trì, tử tế, độc lập, hài hước…) và cách nhân vật giải quyết thử thách — đó là chiến lược em đang muốn dùng cho chính mình.
 - Kênh/sách/chương trình yêu thích (q2) cho biết MÔI TRƯỜNG em muốn thuộc về; quy chủ đề về nhóm Holland (kinh doanh, thời trang, quảng cáo → E/A; khoa học, khám phá → I; tâm lí, thiện nguyện → S; kĩ thuật, sửa chữa, thể thao, thiên nhiên → R; sắp xếp, tài chính cá nhân, quy trình → C; sáng tác, âm nhạc, phim → A).
 - Câu chuyện/phim yêu thích (q3) là "kịch bản": nhân vật chính đối mặt hoàn cảnh nào, vượt qua ra sao, đạt được gì — phản ánh hành trình em đang tự viết cho bước ngoặt hiện tại.
 - Châm ngôn (q4) là "lời khuyên cho chính mình" khi gặp bế tắc.
 - Ghép thành CHỦ ĐỀ: nối nỗi bận tâm/thử thách của em với cách em muốn làm chủ nó và đóng góp cho người khác (chuyển từ bị động sang chủ động). Chủ đề viết thành 1 câu ngắn, giàu hình ảnh, rút từ chính lời em.
2. Holland – Nghề mơ ước (q5): quy từng nghề em kể ra mã Holland (ví dụ: bác sĩ ISR, kĩ sư RIE, giáo viên SAE, nhà thiết kế AES, kế toán CEI, cảnh sát RSE, lập trình viên IRC, nhà báo ASE, doanh nhân ESC), tìm các chữ cái lặp lại nhiều nhất → "ma_bai_viet" (2–3 chữ). Nghề mơ ước có giá trị dự báo lựa chọn thực tế cao, tương đương trắc nghiệm; khi khớp với mã trắc nghiệm, nhận định đáng tin hơn.
 Dấu hiệu từng nhóm trong lời kể: R – dùng công cụ, sửa chữa, lắp ráp, ngoài trời, vận động, cây trồng/vật nuôi, thích thành quả hữu hình. I – quan sát, phân tích, thí nghiệm, giải bài toán, tò mò bản chất. A – sáng tác, vẽ, nhạc, kịch, thiết kế, biểu đạt cảm xúc, thích tự do, độc đáo. S – dạy, giúp đỡ, lắng nghe, chăm sóc, làm nhóm, quan tâm cộng đồng. E – thuyết phục, dẫn dắt, kinh doanh, tổ chức, đàm phán, diễn thuyết, muốn ảnh hưởng/thăng tiến. C – sắp xếp, số liệu, hồ sơ, quy trình, chính xác, ổn định.
3. SCCT (Lent, Brown, Hackett) và Bandura:
 - Tự hiệu quả: tìm 4 nguồn — trải nghiệm thành công (q6, q7), học từ hình mẫu (q1), được khen/động viên, cảm xúc khi làm (hào hứng hay lo âu).
 - Kỳ vọng kết quả: vật chất (thu nhập), xã hội (được gia đình tôn trọng, địa vị), tự đánh giá (tự hào, ý nghĩa) — thường lộ ra ở q8, q9.
 - Rào cản và hỗ trợ (q9, q10): phân biệt rào cản thật và cảm nhận; đánh giá khả năng ứng phó; chỉ ra nguồn hỗ trợ em đang có.
 - Cách em giải thích thành công/thất bại ở q7 (do nỗ lực hay do "trời sinh") cho biết em có tin mình tiến bộ được không.
4. Mức sẵn sàng (Savickas, 4 khả năng): quan tâm đến tương lai, tự chủ, tò mò khám phá, tự tin. Nếu q10 cho thấy chiều nào còn yếu, gợi ý một việc cụ thể (viết kế hoạch 1 năm; luyện ra quyết định nhỏ; tham quan, phỏng vấn người trong nghề; đặt mục tiêu nhỏ và ghi lại thành công).
5. Tính cách và sở thích (Larson và cộng sự, 2002): cởi mở đi cùng A (r=.48) và I (r=.28); hướng ngoại đi cùng E (r=.41) và S (r=.31); dễ chịu đi cùng S (r=.19); tận tâm đi cùng C (r=.25). Chỉ nhắc khi lời kể thể hiện rõ.
6. Bối cảnh gia đình Việt Nam/châu Á (Leong; Leung và cộng sự; Sawitri và cộng sự): quyết định chọn nghề thường là quyết định chung của gia đình; việc em lắng nghe cha mẹ là biểu hiện của "cái tôi gắn kết", KHÔNG phải thiếu trưởng thành. Khi mong muốn của em và gia đình khác nhau, gợi ý cách trao đổi, tìm điểm chung, thương lượng (ví dụ một ngành dung hoà, hoặc cùng đi tìm hiểu thông tin) — tuyệt đối không xúi đối đầu, không phán xét cha mẹ.

QUY TẮC VIẾT:
- Mỗi nhận định phải dựa trên bằng chứng. Trường "trich" chỉ chứa những CÂU/CỤM TỪ CHÉP NGUYÊN VĂN từ bài viết của em (không sửa chữ, không thêm bớt, 1–3 trích/nhận định, mỗi trích ngắn gọn). Không có bằng chứng thì không đưa ra nhận định.
- "co_so": ghi ngắn khung lí thuyết dùng cho nhận định (ví dụ "Savickas – hình mẫu", "Holland – nghề mơ ước", "SCCT – tự hiệu quả").
- "nhan_dinh": 5–7 mục, mỗi mục một "khia_canh" trong các khía cạnh: "Hình ảnh bản thân", "Sở thích nổi bật", "Điểm mạnh và niềm tin vào bản thân", "Giá trị em coi trọng", "Môi trường làm việc phù hợp", "Gia đình và các mối quan hệ", "Điều em băn khoăn". Mỗi nội dung 2–4 câu, cụ thể, nói về CHÍNH EM, tránh câu chung chung ai đọc cũng thấy đúng.
- "doi_chieu": so sánh mã từ bài viết với mã trắc nghiệm (đã cho): trùng ở đâu, khác ở đâu, và ý nghĩa; nếu khác nhiều, nói rõ đây là chủ đề nên trao đổi thêm, không vội kết luận.
- "nhom_nganh": chọn 5–7 nhóm từ "danh_sach_nhom_nganh" (chỉ dùng "id" có trong danh sách), xếp từ phù hợp nhất; "ly_do" 2–3 câu nối với sở thích, điểm mạnh, giá trị trong bài của em; "can_tim_hieu" là 1 câu nêu điều em cần kiểm chứng (môn then chốt, môi trường thực tế, điều kiện tuyển sinh). Nếu nghề mơ ước của em nằm ngoài danh sách, vẫn chọn nhóm gần nhất và nhắc trong lý do.
- "buoc_tiep": 4–6 việc cụ thể, làm được trong 1–3 tháng (ai, ở đâu, làm gì).
- "chan_dung": 3–5 câu khắc hoạ em như một con người cụ thể; "loi_nhan": 2–3 câu động viên chân thành, gắn với điều em viết.
- KHÔNG gán nhãn cố định ("em là người…" vĩnh viễn), KHÔNG phán "em phải học ngành X", nhấn mạnh sở thích còn phát triển. KHÔNG dùng thuật ngữ tiếng Anh trừ tên lí thuyết trong "co_so". KHÔNG nhắc đến việc mình là công cụ, phần mềm hay cách văn bản được tạo ra; viết như bản tư vấn của người làm chuyên môn. KHÔNG nhắc tên, trường của học sinh.
- AN TOÀN: nếu bài viết có dấu hiệu em đang gặp nguy hiểm, bị bạo hành, có ý nghĩ tự làm hại hoặc khủng hoảng tâm lí (kể cả gợi ý trong "dau_hieu_can_quan_tam_so_bo"), đặt "can_quan_tam" = true, "ly_do_quan_tam" mô tả ngắn cho giáo viên; trong "loi_nhan" nhẹ nhàng khuyến khích em chia sẻ với người lớn tin cậy (thầy cô chủ nhiệm, cha mẹ) và cho biết em có thể gọi Tổng đài quốc gia bảo vệ trẻ em 111 (miễn phí, 24/7). Không chẩn đoán, không bình luận chi tiết. Nếu không có dấu hiệu: "can_quan_tam" = false, "ly_do_quan_tam" = "".
- Nếu một câu trả lời trống, quá ngắn hoặc lạc đề, bỏ qua câu đó, không bịa thêm.
- KHÔNG nêu mã tổ hợp xét tuyển (A00, B00, D01…), điểm chuẩn, học phí, mức lương hay bất kì con số tuyển sinh, thị trường lao động nào — các thông tin này đổi theo từng năm. Thay vào đó, hướng em tự kiểm chứng ở đề án tuyển sinh chính thức của các trường và hỏi thầy cô.
TXT;
}
