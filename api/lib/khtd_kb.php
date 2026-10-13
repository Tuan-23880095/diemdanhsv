<?php
/**
 * api/lib/khtd_kb.php — Bộ tri thức chuẩn để chấm bài nhận diện khoáng vật và đá (phiếu luyện thi cuối khóa,
 * dự báo điểm thi từ các phiếu 1–6). docs/04-API-PHP.md mục 33.
 *
 * Nguồn (đã đối chiếu, cũng đưa lên NotebookLM "2026 Khoa học Trái đất"):
 *   [BMCS]  Tài liệu thực tập Địa chất đại cương — BM Địa chất cơ sở, Khoa Địa chất, ĐH KHTN TP.HCM (1999):
 *           "Các tính chất của khoáng vật", "Bảng các khoáng vật thường gặp", "Thạch học", "Bảng phân loại đá magma",
 *           "Phân loại đá trầm tích theo thành phần hoá học / theo hạt độ", "Bảng phân loại đá biến chất".
 *   [KVTĐ]  Khoáng vật tạo đá (Hoàng Văn Hải). [THMB] Thạch học đá magma và biến chất (H. Trung).
 *   [THTT]  Thạch học đá trầm tích T1. Đề cương LEC10002 KHTĐ 2026 (danh mục mẫu thi).
 *
 * Quy ước khoá so khớp (ok-list): mỗi phần tử là một CỤM từ đã bỏ dấu, chữ thường; "a+b" = phải có cả a và b.
 * Một trường đạt nếu khớp ít nhất một phần tử. Số (độ cứng, tỷ trọng) so theo khoảng [min, max] ± dung sai.
 */

function khtd_kb(): array
{
    static $kb = null;
    if ($kb !== null) return $kb;

    $kv = [
        'thach_anh' => [
            'ten' => 'Thạch anh (Quartz)', 'alias' => ['thach anh', 'quartz', 'quart', 'thach anh tim', 'amethyst', 'thach anh am khoi', 'thach anh hong', 'thach anh trang'],
            'tphh' => 'SiO₂', 'tphh_ok' => ['sio2', 'silic dioxit', 'dioxit silic', 'oxit silic', 'silic oxit'],
            'cung' => [7, 7],
            'cat_khai' => 'Không có cát khai; vết vỡ vỏ sò (trôn ốc)', 'cat_khai_ok' => ['khong', 'vo so', 'tron oc', 'conchoidal', 'none'],
            'tinh_the' => 'Lăng trụ 6 mặt, đầu chóp; sọc ngang trên mặt lăng trụ', 'tinh_the_ok' => ['lang tru', '6 mat', 'sau mat', 'luc giac', 'chop', 'khoi hat'],
            'vach' => 'Trắng / không màu', 'vach_ok' => ['trang', 'khong mau'],
            'ty_trong' => [2.6, 2.7],
            'mau' => 'Không màu, trắng sữa, tím (amethyst), hồng, ám khói, xám', 'mau_ok' => ['khong mau', 'trang', 'tim', 'hong', 'am khoi', 'xam', 'trong suot'],
            'anh' => 'Thủy tinh (mỡ trên mặt vỡ)', 'anh_ok' => ['thuy tinh', 'mo'],
            'khac' => 'Vạch được kính; không sủi bọt HCl; trong đá magma có màu xám, ánh mỡ', 'khac_ok' => ['vach duoc kinh', 'vach kinh', 'khong sui', 'khong phan ung', 'ap dien', 'trong suot', 'ban trong'],
        ],
        'orthoclas' => [
            'ten' => 'Orthoclas – fenpat K (Orthoclase)', 'alias' => ['orthoclas', 'orthoclase', 'orthocla', 'octocla', 'octoclaz', 'ortoclas', 'orthoclaz', 'fenpat k', 'felspat k', 'feldspar k', 'fenpat kali', 'felspat kali', 'k feldspar', 'truong thach kali', 'microclin', 'microcline', 'fenspat kali', 'fenspat k'],
            'tphh' => 'KAlSi₃O₈', 'tphh_ok' => ['kalsi3o8', 'silicat+kali', 'silicat+k', 'alumosilicat+k', 'aluminosilicat+k', 'kali+nhom+silic'],
            'cung' => [6, 6.5],
            'cat_khai' => '2 hướng cát khai tốt, gần vuông góc (90°)', 'cat_khai_ok' => ['2 huong', 'hai huong', '2 phuong', 'hai phuong', 'vuong goc', '90'],
            'tinh_the' => 'Dạng tấm, lăng trụ ngắn; song tinh Carlsbad', 'tinh_the_ok' => ['tam', 'lang tru', 'song tinh', 'carlsbad', 'cot ngan'],
            'vach' => 'Trắng', 'vach_ok' => ['trang', 'khong mau'],
            'ty_trong' => [2.55, 2.63],
            'mau' => 'Hồng, hồng thịt, trắng, xám, vàng nhạt', 'mau_ok' => ['hong', 'hong thit', 'do nhat', 'trang', 'xam', 'vang nhat', 'nau nhat'],
            'anh' => 'Thủy tinh, xà cừ trên mặt cát khai', 'anh_ok' => ['thuy tinh', 'xa cu', 'ngoc trai'],
            'khac' => 'Không có sọc song tinh đa hợp (phân biệt plagioclas); mặt cát khai phản chiếu chói', 'khac_ok' => ['khong co soc', 'khong soc', 'perthit', 'song tinh carlsbad', 'mat cat khai choi', 'phan chieu'],
        ],
        'plagioclas' => [
            'ten' => 'Plagioclas – fenpat Na-Ca (Plagioclase)', 'alias' => ['plagioclas', 'plagioclase', 'plagiocla', 'plagioclaz', 'fenpat na', 'felspat na', 'fenpat plagiocla', 'fenpat natri', 'albit', 'albite', 'anorthit', 'labrador', 'andesin', 'fenspat na'],
            'tphh' => 'NaAlSi₃O₈ – CaAl₂Si₂O₈', 'tphh_ok' => ['naalsi3o8', 'caal2si2o8', 'silicat+na', 'silicat+ca', 'silicat+natri', 'silicat+canxi', 'silicat+calci', 'alumosilicat+na'],
            'cung' => [6, 6.5],
            'cat_khai' => '2 hướng cát khai tốt, xiên góc 86°/94°', 'cat_khai_ok' => ['2 huong', 'hai huong', '2 phuong', 'hai phuong', '86', '94', 'gan vuong goc', 'xien'],
            'tinh_the' => 'Dạng tấm, que dài (trong đá hạt mịn)', 'tinh_the_ok' => ['tam', 'que', 'lang tru', 'song tinh'],
            'vach' => 'Trắng', 'vach_ok' => ['trang', 'khong mau'],
            'ty_trong' => [2.6, 2.76],
            'mau' => 'Trắng, xám, xám xanh, không màu', 'mau_ok' => ['trang', 'xam', 'xam xanh', 'khong mau', 'xam den'],
            'anh' => 'Thủy tinh, xà cừ', 'anh_ok' => ['thuy tinh', 'xa cu'],
            'khac' => 'Có sọc song tinh (đa hợp) song song trên mặt cát khai', 'khac_ok' => ['soc', 'song tinh da hop', 'song tinh'],
        ],
        'pyroxen' => [
            'ten' => 'Pyroxen – augit (Augite)', 'alias' => ['pyroxen', 'pyroxene', 'piroxen', 'augit', 'augite', 'ogit', 'piroxene'],
            'tphh' => 'Silicat Ca, Mg, Fe (Al) – (Ca,Mg,Fe)₂Si₂O₆', 'tphh_ok' => ['si2o6', 'sio3', 'silicat+mg', 'silicat+fe', 'silicat+ca', 'silicat+magie', 'silicat+sat'],
            'cung' => [5.5, 6],
            'cat_khai' => '2 hướng cát khai gần vuông góc (87°/93°)', 'cat_khai_ok' => ['2 huong', 'hai huong', '2 phuong', '87', '93', 'gan vuong goc', 'vuong goc'],
            'tinh_the' => 'Lăng trụ ngắn, mặt cắt ngang 4 hoặc 8 cạnh', 'tinh_the_ok' => ['lang tru ngan', 'lang tru', '8 canh', 'tam canh', '4 canh', 'hat'],
            'vach' => 'Lục xám nhạt / trắng xám', 'vach_ok' => ['luc', 'xam', 'trang xam', 'xam nhat'],
            'ty_trong' => [3.2, 3.5],
            'mau' => 'Lục sẫm, đen lục, đen', 'mau_ok' => ['luc sam', 'den', 'luc den', 'xanh den', 'nau den', 'luc'],
            'anh' => 'Thủy tinh, mờ', 'anh_ok' => ['thuy tinh', 'mo', 'dull'],
            'khac' => 'Phân biệt hornblend bằng góc cát khai ~90° (hornblend 56°/124°); tinh thể ngắn mập', 'khac_ok' => ['ngan map', 'goc cat khai', 'phan biet hornblend', 'phan biet amphibol'],
        ],
        'hornblend' => [
            'ten' => 'Hornblend – amphibol (Hornblende)', 'alias' => ['hornblend', 'hornblende', 'horblend', 'amphibol', 'amphibole', 'amfibol', 'hocblen'],
            'tphh' => 'Silicat Ca, Na, Mg, Fe, Al ngậm nước (có OH)', 'tphh_ok' => ['si8o22', 'oh 2', 'silicat+mg', 'silicat+fe', 'silicat+ca', 'silicat+ngam nuoc'],
            'cung' => [5, 6],
            'cat_khai' => '2 hướng cát khai tốt, xiên góc 56°/124°', 'cat_khai_ok' => ['56', '124', '60', '120', '2 huong', 'hai huong'],
            'tinh_the' => 'Lăng trụ dài, mặt cắt ngang 6 cạnh', 'tinh_the_ok' => ['lang tru dai', 'que', 'kim', '6 canh', 'lang tru'],
            'vach' => 'Trắng xám, lục xám', 'vach_ok' => ['trang xam', 'luc xam', 'xam', 'trang'],
            'ty_trong' => [3.0, 3.4],
            'mau' => 'Lục sẫm, đen', 'mau_ok' => ['luc sam', 'den', 'luc den', 'xanh den'],
            'anh' => 'Thủy tinh', 'anh_ok' => ['thuy tinh'],
            'khac' => 'Tinh thể dài; góc cát khai 56°/124° (phân biệt pyroxen)', 'khac_ok' => ['dai', 'kim', 'goc cat khai'],
        ],
        'olivin' => [
            'ten' => 'Olivin (Olivine)', 'alias' => ['olivin', 'olivine', 'peridot', 'oliven'],
            'tphh' => '(Mg,Fe)₂SiO₄', 'tphh_ok' => ['mg fe 2sio4', 'mgfe2sio4', '2sio4', 'sio4', 'silicat+mg', 'silicat+fe', 'silicat+magie'],
            'cung' => [6.5, 7],
            'cat_khai' => 'Cát khai kém / không rõ; vết vỡ vỏ sò', 'cat_khai_ok' => ['kem', 'xau', 'khong', 'vo so', 'khong ro'],
            'tinh_the' => 'Hạt nhỏ kết khối, khó thấy tinh thể', 'tinh_the_ok' => ['hat', 'ket khoi', 'hat nho', 'dang hat'],
            'vach' => 'Trắng / không màu', 'vach_ok' => ['trang', 'khong mau'],
            'ty_trong' => [3.2, 4.4],
            'mau' => 'Lục ô-liu, lục vàng, vàng nâu', 'mau_ok' => ['luc', 'o liu', 'oliu', 'xanh o liu', 'luc vang', 'vang', 'xanh la'],
            'anh' => 'Thủy tinh', 'anh_ok' => ['thuy tinh'],
            'khac' => 'Gặp trong bazan, gabro, peridotit (đá mafic – siêu mafic); hạt màu xanh ô-liu', 'khac_ok' => ['bazan', 'basalt', 'peridotit', 'sieu mafic', 'mafic', 'gabro'],
        ],
        'biotit' => [
            'ten' => 'Biotit – mica đen (Biotite)', 'alias' => ['biotit', 'biotite', 'mica den', 'bi otit'],
            'tphh' => 'K(Mg,Fe)₃AlSi₃O₁₀(OH)₂ – silicat K, Mg, Fe, Al ngậm nước', 'tphh_ok' => ['alsi3o10', 'si3o10', 'silicat+k', 'silicat+mg', 'silicat+fe', 'silicat+ngam nuoc'],
            'cung' => [2.5, 3],
            'cat_khai' => '1 hướng cát khai hoàn toàn, tách thành lá mỏng', 'cat_khai_ok' => ['1 huong', 'mot huong', '1 phuong', 'hoan toan', 'tach la', 'la mong'],
            'tinh_the' => 'Dạng tấm, vảy 6 cạnh', 'tinh_the_ok' => ['tam', 'vay', '6 canh', 'la'],
            'vach' => 'Trắng / lục xám', 'vach_ok' => ['trang', 'xam', 'luc xam', 'khong mau'],
            'ty_trong' => [2.8, 3.2],
            'mau' => 'Đen, nâu đen, lục sẫm', 'mau_ok' => ['den', 'nau', 'nau den', 'luc sam'],
            'anh' => 'Thủy tinh – xà cừ (kim cương trên mặt cát khai)', 'anh_ok' => ['thuy tinh', 'xa cu', 'kim cuong', 'ngoc trai'],
            'khac' => 'Lá mỏng đàn hồi, móng tay vạch được', 'khac_ok' => ['dan hoi', 'deo', 'la mong', 'tach la', 'mong tay'],
        ],
        'muscovit' => [
            'ten' => 'Muscovit – mica trắng (Muscovite)', 'alias' => ['muscovit', 'muscovite', 'mica trang', 'moscovit', 'muscovid'],
            'tphh' => 'KAl₂(AlSi₃O₁₀)(OH)₂ – silicat K, Al ngậm nước', 'tphh_ok' => ['alsi3o10', 'si3o10', 'kal2', 'silicat+k', 'silicat+al', 'silicat+ngam nuoc'],
            'cung' => [2, 2.5],
            'cat_khai' => '1 hướng cát khai hoàn toàn, tách lá mỏng', 'cat_khai_ok' => ['1 huong', 'mot huong', '1 phuong', 'hoan toan', 'tach la', 'la mong'],
            'tinh_the' => 'Dạng tấm, vảy 6 cạnh', 'tinh_the_ok' => ['tam', 'vay', '6 canh', 'la'],
            'vach' => 'Trắng / không màu', 'vach_ok' => ['trang', 'khong mau'],
            'ty_trong' => [2.76, 2.88],
            'mau' => 'Không màu, trắng bạc, vàng nhạt', 'mau_ok' => ['khong mau', 'trang', 'bac', 'vang nhat', 'trong suot'],
            'anh' => 'Xà cừ, thủy tinh', 'anh_ok' => ['xa cu', 'thuy tinh', 'ngoc trai', 'lua'],
            'khac' => 'Lá mỏng trong suốt, đàn hồi', 'khac_ok' => ['dan hoi', 'trong suot', 'la mong', 'tach la'],
        ],
        'calcit' => [
            'ten' => 'Calcit (Calcite)', 'alias' => ['calcit', 'calcite', 'canxit', 'can xit', 'calxit', 'dai phuong thach', 'spar'],
            'tphh' => 'CaCO₃', 'tphh_ok' => ['caco3', 'canxi cacbonat', 'calci carbonat', 'cacbonat canxi', 'carbonat calci', 'carbonat+ca'],
            'cung' => [3, 3],
            'cat_khai' => '3 hướng cát khai hoàn toàn (mặt thoi)', 'cat_khai_ok' => ['3 huong', 'ba huong', '3 phuong', 'thoi', 'khoi thoi', 'mat thoi'],
            'tinh_the' => 'Khối thoi, lăng trụ, tháp nhọn (scalenohedral)', 'tinh_the_ok' => ['thoi', 'khoi thoi', 'lang tru', 'thap'],
            'vach' => 'Trắng', 'vach_ok' => ['trang', 'khong mau'],
            'ty_trong' => [2.7, 2.72],
            'mau' => 'Không màu, trắng, vàng, hồng, xám', 'mau_ok' => ['khong mau', 'trang', 'vang', 'hong', 'xam', 'trong suot'],
            'anh' => 'Thủy tinh', 'anh_ok' => ['thuy tinh', 'xa cu'],
            'khac' => 'Sủi bọt mạnh với HCl loãng nguội; khúc xạ kép (băng lan)', 'khac_ok' => ['sui', 'hcl', 'axit', 'khuc xa kep', 'luong chiet', 'nhan doi'],
        ],
        'talc' => [
            'ten' => 'Talc (hoạt thạch)', 'alias' => ['talc', 'tan', 'hoat thach', 'hoat khoang', 'talk'],
            'tphh' => 'Mg₃Si₄O₁₀(OH)₂ – silicat Mg ngậm nước', 'tphh_ok' => ['mg3si4o10', 'si4o10', 'silicat+mg', 'silicat+magie'],
            'cung' => [1, 1],
            'cat_khai' => '1 hướng hoàn toàn (vảy, lá)', 'cat_khai_ok' => ['1 huong', 'mot huong', 'hoan toan', 'vay', 'la'],
            'tinh_the' => 'Khối vảy, dạng lá, khối đặc', 'tinh_the_ok' => ['vay', 'la', 'khoi', 'ket khoi'],
            'vach' => 'Trắng', 'vach_ok' => ['trang'],
            'ty_trong' => [2.7, 2.8],
            'mau' => 'Lục táo, trắng, xám, bạc', 'mau_ok' => ['luc', 'trang', 'xam', 'bac', 'luc tao'],
            'anh' => 'Xà cừ, mỡ', 'anh_ok' => ['xa cu', 'mo', 'ngoc trai'],
            'khac' => 'Sờ trơn như xà phòng; móng tay vạch được; mềm nhất thang Mohs', 'khac_ok' => ['tron', 'xa phong', 'mong tay', 'mem nhat', 'nhon'],
        ],
        'thach_cao' => [
            'ten' => 'Thạch cao (Gypsum)', 'alias' => ['thach cao', 'gypsum', 'gyps', 'thach cao tinh the', 'selenit'],
            'tphh' => 'CaSO₄·2H₂O', 'tphh_ok' => ['caso4', 'canxi sunfat', 'calci sulfat', 'sunfat+ca', 'sulfat+ca'],
            'cung' => [2, 2],
            'cat_khai' => '1 hướng hoàn toàn (+ 2 hướng kém)', 'cat_khai_ok' => ['1 huong', 'mot huong', 'hoan toan', 'tach la'],
            'tinh_the' => 'Dạng tấm, sợi, hạt; song tinh đuôi én', 'tinh_the_ok' => ['tam', 'soi', 'duoi en', 'hat', 'la'],
            'vach' => 'Trắng', 'vach_ok' => ['trang'],
            'ty_trong' => [2.3, 2.35],
            'mau' => 'Không màu, trắng, xám', 'mau_ok' => ['khong mau', 'trang', 'xam', 'trong suot', 'hong'],
            'anh' => 'Thủy tinh, xà cừ, tơ (dạng sợi)', 'anh_ok' => ['thuy tinh', 'xa cu', 'to', 'lua'],
            'khac' => 'Móng tay vạch được; không sủi HCl (phân biệt calcit)', 'khac_ok' => ['mong tay', 'khong sui', 'mem'],
        ],
        'fluorit' => [
            'ten' => 'Fluorit (Fluorite)', 'alias' => ['fluorit', 'fluorite', 'florit', 'fluorin', 'huynh thach'],
            'tphh' => 'CaF₂', 'tphh_ok' => ['caf2', 'canxi florua', 'calci fluorid', 'florua+ca'],
            'cung' => [4, 4],
            'cat_khai' => '4 hướng hoàn toàn (bát diện)', 'cat_khai_ok' => ['4 huong', 'bon huong', 'bat dien', '4 phuong'],
            'tinh_the' => 'Lập phương, bát diện', 'tinh_the_ok' => ['lap phuong', 'bat dien', 'khoi'],
            'vach' => 'Trắng', 'vach_ok' => ['trang'],
            'ty_trong' => [3.1, 3.2],
            'mau' => 'Tím, lục, vàng, xanh, không màu, hồng', 'mau_ok' => ['tim', 'luc', 'vang', 'xanh', 'khong mau', 'hong', 'trang'],
            'anh' => 'Thủy tinh', 'anh_ok' => ['thuy tinh'],
            'khac' => 'Phát quang dưới tia cực tím; đồng xu không vạch được, đinh vạch được', 'khac_ok' => ['phat quang', 'huynh quang', 'tia cuc tim', 'uv'],
        ],
        'apatit' => [
            'ten' => 'Apatit (Apatite)', 'alias' => ['apatit', 'apatite'],
            'tphh' => 'Ca₅(PO₄)₃(F,Cl,OH)', 'tphh_ok' => ['ca5', 'po4', 'photphat+ca', 'phosphat+ca', 'phosphat calci', 'photphat canxi'],
            'cung' => [5, 5],
            'cat_khai' => 'Cát khai kém (xấu)', 'cat_khai_ok' => ['kem', 'xau', 'khong ro', 'khong'],
            'tinh_the' => 'Lăng trụ 6 cạnh, hạt kết khối', 'tinh_the_ok' => ['lang tru', '6 canh', 'luc giac', 'hat'],
            'vach' => 'Trắng', 'vach_ok' => ['trang'],
            'ty_trong' => [3.1, 3.2],
            'mau' => 'Lục, nâu, xanh, tím, vàng, không màu', 'mau_ok' => ['luc', 'nau', 'xanh', 'tim', 'vang', 'khong mau'],
            'anh' => 'Thủy tinh, nhựa', 'anh_ok' => ['thuy tinh', 'nhua'],
            'khac' => 'Lưỡi dao vạch được khó; không vạch được kính; nguồn phosphat', 'khac_ok' => ['dao', 'khong vach duoc kinh', 'phan lan', 'phosphat'],
        ],
        'topaz' => [
            'ten' => 'Topaz', 'alias' => ['topaz', 'hoang ngoc', 'topa'],
            'tphh' => 'Al₂SiO₄(F,OH)₂', 'tphh_ok' => ['al2sio4', 'sio4', 'silicat+al', 'silicat+nhom', 'silicat+f'],
            'cung' => [8, 8],
            'cat_khai' => '1 hướng hoàn toàn (mặt đáy)', 'cat_khai_ok' => ['1 huong', 'mot huong', 'hoan toan', 'mat day'],
            'tinh_the' => 'Lăng trụ có sọc dọc, đầu chóp nhọn', 'tinh_the_ok' => ['lang tru', 'soc doc', 'chop'],
            'vach' => 'Trắng / không màu', 'vach_ok' => ['trang', 'khong mau'],
            'ty_trong' => [3.4, 3.6],
            'mau' => 'Không màu, vàng, hồng, xanh lam nhạt', 'mau_ok' => ['khong mau', 'vang', 'hong', 'xanh', 'lam'],
            'anh' => 'Thủy tinh', 'anh_ok' => ['thuy tinh'],
            'khac' => 'Vạch được thạch anh; nặng hơn thạch anh', 'khac_ok' => ['vach duoc thach anh', 'cung hon thach anh', 'nang'],
        ],
        'corindon' => [
            'ten' => 'Corindon (Corundum; ruby, sapphire)', 'alias' => ['corindon', 'corundum', 'corindom', 'corund', 'ruby', 'rubi', 'sapphire', 'saphia', 'saphir', 'hong ngoc', 'lam ngoc'],
            'tphh' => 'Al₂O₃', 'tphh_ok' => ['al2o3', 'oxit nhom', 'nhom oxit', 'alumin'],
            'cung' => [9, 9],
            'cat_khai' => 'Không cát khai (giả cát khai – tách theo nhiều hướng)', 'cat_khai_ok' => ['khong', 'gia cat khai', 'tach', 'parting'],
            'tinh_the' => 'Lăng trụ 6 cạnh, hình thùng, dạng tấm', 'tinh_the_ok' => ['lang tru', '6 canh', 'thung', 'tam', 'luc giac'],
            'vach' => 'Trắng / không màu', 'vach_ok' => ['trang', 'khong mau'],
            'ty_trong' => [3.9, 4.1],
            'mau' => 'Xám, nâu, đỏ (ruby), xanh lam (sapphire)', 'mau_ok' => ['xam', 'nau', 'do', 'xanh', 'lam', 'hong'],
            'anh' => 'Kim cương – thủy tinh', 'anh_ok' => ['kim cuong', 'thuy tinh'],
            'khac' => 'Rất cứng (chỉ kém kim cương), nặng; vạch được topaz', 'khac_ok' => ['rat cung', 'nang', 'vach duoc topaz', 'chi kem kim cuong'],
        ],
        'kim_cuong' => [
            'ten' => 'Kim cương (Diamond)', 'alias' => ['kim cuong', 'diamond'],
            'tphh' => 'C (carbon)', 'tphh_ok' => ['c', 'carbon', 'cacbon'],
            'cung' => [10, 10],
            'cat_khai' => '4 hướng hoàn toàn (bát diện)', 'cat_khai_ok' => ['4 huong', 'bon huong', 'bat dien'],
            'tinh_the' => 'Bát diện, lập phương', 'tinh_the_ok' => ['bat dien', 'lap phuong'],
            'vach' => 'Không có vết vạch (cứng hơn bản sứ)', 'vach_ok' => ['khong', 'khong mau', 'khong co'],
            'ty_trong' => [3.5, 3.53],
            'mau' => 'Không màu, vàng, nâu, xám', 'mau_ok' => ['khong mau', 'vang', 'nau', 'xam', 'trong suot'],
            'anh' => 'Kim cương', 'anh_ok' => ['kim cuong'],
            'khac' => 'Cứng nhất thang Mohs, vạch được mọi khoáng vật', 'khac_ok' => ['cung nhat', 'vach duoc moi'],
        ],
    ];

    $MAGMA = ['magma', 'macma', 'hoa lap', 'xam nhap', 'phun trao', 'nui lua', 'igneous'];
    $TT = ['tram tich', 'sedimentary', 'tram tich vun', 'tram tich hoa hoc', 'tram tich sinh hoa'];
    $BC = ['bien chat', 'metamorphic'];
    $da = [
        'granit' => [
            'ten' => 'Granit (đá hoa cương)', 'alias' => ['granit', 'granite', 'hoa cuong', 'da hoa cuong', 'granit biotit', 'granit porphyr'],
            'nhom' => 'magma', 'nhom_txt' => 'Đá magma xâm nhập (sâu), axit – felsic',
            'kt' => 'Hạt thô – vừa, toàn tinh (hiển tinh), đều hạt', 'kt_ok' => ['hat tho', 'hat vua', 'hat lon', 'hat to', 'hien tinh', 'toan tinh', 'deu hat', 'phaneritic', 'granular', 'hat deu'],
            'kv' => 'Thạch anh, fenpat K (orthoclas) > plagioclas, biotit/muscovit, ít hornblend', 'kv_ok' => [['thach anh', 'quartz'], ['orthocla', 'fenpat', 'felspat', 'feldspar', 'truong thach', 'fenspat'], ['mica', 'biotit', 'muscovit', 'hornblend', 'amphibol']], 'kv_need' => 3,
            'dd' => 'Sáng màu (hồng, xám trắng); thấy rõ hạt thạch anh xám ánh mỡ, fenpat hồng/trắng, vảy mica đen', 'dd_ok' => ['sang mau', 'hong', 'xam trang', 'thay ro hat', 'hat thach anh', 'mica den', 'loang lo'],
            'dk' => 'Magma axit kết tinh chậm ở sâu trong vỏ Trái đất (xâm nhập sâu)', 'dk_ok' => ['xam nhap', 'nguoi cham', 'duoi sau', 'o sau', 'ket tinh cham'],
        ],
        'rhyolit' => [
            'ten' => 'Rhyolit (đá lưu vân)', 'alias' => ['rhyolit', 'rhyolite', 'ryolit', 'riolit', 'riolite', 'luu van', 'da luu van'],
            'nhom' => 'magma', 'nhom_txt' => 'Đá magma phun trào, axit – felsic',
            'kt' => 'Vi hạt (ẩn tinh) hoặc porphyr (vân ban); có thể có cấu tạo dòng chảy', 'kt_ok' => ['vi hat', 'an tinh', 'hat min', 'porphyr', 'van ban', 'ban tinh', 'dong chay', 'aphanitic', 'hat nho'],
            'kv' => 'Thạch anh, fenpat K (ban tinh), nền vi hạt/thủy tinh', 'kv_ok' => [['thach anh', 'quartz'], ['orthocla', 'fenpat', 'felspat', 'feldspar', 'sanidin', 'fenspat'], ['biotit', 'mica', 'thuy tinh']], 'kv_need' => 2,
            'dd' => 'Sáng màu (hồng nhạt, xám nhạt, kem), hạt mịn; có thể thấy ban tinh thạch anh/fenpat và vân dòng chảy', 'dd_ok' => ['sang mau', 'hong nhat', 'xam nhat', 'kem', 'van dong chay', 'phan dai', 'ban tinh', 'hat min'],
            'dk' => 'Dung nham axit phun trào, nguội nhanh trên mặt đất', 'dk_ok' => ['phun trao', 'nguoi nhanh', 'mat dat', 'dung nham', 'nui lua'],
        ],
        'diorit' => [
            'ten' => 'Diorit', 'alias' => ['diorit', 'diorite', 'dioryt'],
            'nhom' => 'magma', 'nhom_txt' => 'Đá magma xâm nhập, trung tính',
            'kt' => 'Hạt vừa – thô, toàn tinh', 'kt_ok' => ['hat tho', 'hat vua', 'hien tinh', 'toan tinh', 'deu hat', 'hat to', 'phaneritic'],
            'kv' => 'Plagioclas (Na-Ca) chủ yếu, hornblend, biotit, pyroxen; ít hoặc không thạch anh', 'kv_ok' => [['plagiocla', 'fenpat', 'felspat', 'feldspar', 'fenspat'], ['hornblend', 'amphibol', 'pyroxen', 'biotit', 'mica']], 'kv_need' => 2,
            'dd' => 'Đốm "muối tiêu" trắng – đen xấp xỉ bằng nhau; hầu như không thấy thạch anh', 'dd_ok' => ['muoi tieu', 'dom trang den', 'trang den', 'lom dom', 'khong co thach anh', 'it thach anh'],
            'dk' => 'Magma trung tính kết tinh chậm ở sâu (xâm nhập)', 'dk_ok' => ['xam nhap', 'nguoi cham', 'o sau', 'duoi sau'],
        ],
        'andesit' => [
            'ten' => 'Andesit', 'alias' => ['andesit', 'andesite', 'andezit', 'an son', 'da an son'],
            'nhom' => 'magma', 'nhom_txt' => 'Đá magma phun trào, trung tính',
            'kt' => 'Vi hạt hoặc porphyr (ban tinh plagioclas, hornblend trên nền mịn)', 'kt_ok' => ['vi hat', 'an tinh', 'hat min', 'porphyr', 'van ban', 'ban tinh', 'aphanitic'],
            'kv' => 'Plagioclas, hornblend, pyroxen, biotit', 'kv_ok' => [['plagiocla', 'fenpat', 'felspat', 'feldspar', 'fenspat'], ['hornblend', 'amphibol', 'pyroxen', 'biotit']], 'kv_need' => 2,
            'dd' => 'Xám, xám lục, xám nâu; nền mịn với ban tinh plagioclas trắng, que hornblend đen', 'dd_ok' => ['xam', 'xam luc', 'ban tinh trang', 'ban tinh', 'nen min'],
            'dk' => 'Dung nham trung tính phun trào ở cung núi lửa (rìa hội tụ), nguội nhanh', 'dk_ok' => ['phun trao', 'nguoi nhanh', 'nui lua', 'hut chim', 'hoi tu', 'cung dao'],
        ],
        'gabro' => [
            'ten' => 'Gabro (Gabbro)', 'alias' => ['gabro', 'gabbro', 'gabrro', 'trong lap'],
            'nhom' => 'magma', 'nhom_txt' => 'Đá magma xâm nhập, bazơ – mafic',
            'kt' => 'Hạt thô – vừa, toàn tinh', 'kt_ok' => ['hat tho', 'hat vua', 'hien tinh', 'toan tinh', 'deu hat', 'hat to', 'phaneritic'],
            'kv' => 'Plagioclas Ca, pyroxen (augit), ± olivin; không có thạch anh', 'kv_ok' => [['plagiocla', 'fenpat', 'felspat', 'feldspar', 'fenspat'], ['pyroxen', 'augit', 'ogit'], ['olivin']], 'kv_need' => 2,
            'dd' => 'Sẫm màu (đen, lục đen), nặng, hạt thô thấy rõ; không có thạch anh', 'dd_ok' => ['sam mau', 'den', 'luc den', 'nang', 'khong co thach anh', 'xam den'],
            'dk' => 'Magma bazơ kết tinh chậm ở sâu (xâm nhập; vỏ đại dương)', 'dk_ok' => ['xam nhap', 'nguoi cham', 'o sau', 'duoi sau', 'vo dai duong'],
        ],
        'bazan' => [
            'ten' => 'Bazan (Basalt)', 'alias' => ['bazan', 'basalt', 'bazalt', 'basan', 'huyen vu', 'da bazan'],
            'nhom' => 'magma', 'nhom_txt' => 'Đá magma phun trào, bazơ – mafic',
            'kt' => 'Vi hạt (ẩn tinh), có thể porphyr; cấu tạo lỗ hổng (lỗ khí), hạnh nhân', 'kt_ok' => ['vi hat', 'an tinh', 'hat min', 'porphyr', 'lo hong', 'lo khi', 'hanh nhan', 'aphanitic', 'xop', 'dac sit'],
            'kv' => 'Plagioclas Ca, pyroxen, olivin', 'kv_ok' => [['plagiocla', 'fenpat', 'felspat', 'feldspar', 'fenspat'], ['pyroxen', 'augit', 'ogit'], ['olivin']], 'kv_need' => 2,
            'dd' => 'Đen – xám đen, nặng, hạt mịn; thường có lỗ khí; có thể thấy hạt olivin xanh', 'dd_ok' => ['den', 'xam den', 'nang', 'lo khi', 'lo hong', 'olivin xanh', 'hat min'],
            'dk' => 'Dung nham bazơ phun trào, nguội nhanh (sống núi đại dương, cao nguyên bazan Tây Nguyên)', 'dk_ok' => ['phun trao', 'nguoi nhanh', 'dung nham', 'nui lua', 'tay nguyen', 'song nui'],
        ],
        'obsidian' => [
            'ten' => 'Obsidian (đá thủy tinh núi lửa)', 'alias' => ['obsidian', 'obsidien', 'obsidan', 'obsidiant', 'da thuy tinh', 'thuy tinh nui lua', 'da vo chai'],
            'nhom' => 'magma', 'nhom_txt' => 'Đá magma phun trào, thường axit (giàu SiO₂)',
            'kt' => 'Thủy tinh (không có hạt tinh thể)', 'kt_ok' => ['thuy tinh', 'glassy', 'vo dinh hinh', 'khong co hat', 'khong ket tinh'],
            'kv' => 'Thủy tinh núi lửa giàu SiO₂, không có khoáng vật kết tinh', 'kv_ok' => [['thuy tinh', 'sio2', 'silic', 'khong co khoang vat', 'vo dinh hinh']], 'kv_need' => 1,
            'dd' => 'Đen bóng (có thể nâu), vết vỡ vỏ sò sắc cạnh', 'dd_ok' => ['den bong', 'bong', 'vo so', 'sac canh', 'sac nhu dao', 'den'],
            'dk' => 'Dung nham nguội cực nhanh, không kịp kết tinh', 'dk_ok' => ['nguoi rat nhanh', 'nguoi nhanh', 'cuc nhanh', 'khong kip ket tinh', 'phun trao'],
        ],
        'da_bot' => [
            'ten' => 'Đá bọt (Pumice)', 'alias' => ['da bot', 'pumice', 'pumic', 'da bot nui lua', 'da nhe', 'da bot bien', 'bot nui lua'],
            'nhom' => 'magma', 'nhom_txt' => 'Đá magma phun trào (vụn núi lửa), thường axit',
            'kt' => 'Thủy tinh xốp, nhiều lỗ hổng (bọt)', 'kt_ok' => ['thuy tinh', 'xop', 'lo hong', 'lo khi', 'bot', 'glassy', 'vesicular'],
            'kv' => 'Thủy tinh núi lửa (giàu SiO₂)', 'kv_ok' => [['thuy tinh', 'sio2', 'silic', 'vo dinh hinh']], 'kv_need' => 1,
            'dd' => 'Rất nhẹ, nổi trên nước; sáng màu; dày đặc lỗ khí', 'dd_ok' => ['nhe', 'noi', 'noi tren nuoc', 'nhieu lo', 'lo khi', 'sang mau', 'xop'],
            'dk' => 'Magma giàu khí phun nổ, khí thoát nhanh khi nguội cực nhanh', 'dk_ok' => ['phun no', 'khi thoat', 'nguoi nhanh', 'phun trao', 'nui lua'],
        ],
        'cat_ket' => [
            'ten' => 'Cát kết (sa thạch)', 'alias' => ['cat ket', 'sa thach', 'sandstone', 'da cat', 'arkose', 'arkoz', 'grauvac', 'greywacke', 'cat ket thach anh'],
            'nhom' => 'tram_tich', 'nhom_txt' => 'Đá trầm tích vụn (cơ học)',
            'kt' => 'Hạt cát 1/16 – 2 mm (0,063–2 mm), gắn kết bởi xi măng', 'kt_ok' => ['hat cat', 'hat vua', '0 063', '0 0625', '1 16', '2 mm', 'cat', 'vun', 'psammit', 'xi mang'],
            'kv' => 'Thạch anh (chủ yếu), fenpat, mảnh đá; xi măng silic, calcit, oxit sắt', 'kv_ok' => [['thach anh', 'quartz'], ['fenpat', 'felspat', 'manh da', 'xi mang', 'mica', 'oxit sat', 'calcit']], 'kv_need' => 1,
            'dd' => 'Sờ nhám tay, thấy hạt cát; phân lớp (lớp xiên); màu đa dạng', 'dd_ok' => ['nham', 'ram', 'thay hat cat', 'phan lop', 'lop xien', 'hat cat'],
            'dk' => 'Cát tích tụ ở sông, bãi biển, cồn cát rồi bị nén, gắn kết', 'dk_ok' => ['song', 'bai bien', 'bien', 'sa mac', 'con cat', 'tich tu', 'gan ket'],
        ],
        'bun_set_ket' => [
            'ten' => 'Bùn – sét kết (Shale, mudstone)', 'alias' => ['bun ket', 'set ket', 'bun set ket', 'phien set', 'da phien set', 'shale', 'mudstone', 'argillit', 'argillite', 'bot ket', 'da bot ket', 'siltstone', 'da set', 'claystone', 'da bun'],
            'nhom' => 'tram_tich', 'nhom_txt' => 'Đá trầm tích vụn hạt mịn (pelit)',
            'kt' => 'Hạt rất mịn < 1/16 mm (không thấy hạt bằng mắt); cấu tạo phân lớp mỏng', 'kt_ok' => ['hat min', 'rat min', 'set', 'bun', '1 16', 'pelit', 'phan lop mong', 'khong thay hat', 'hat nho'],
            'kv' => 'Khoáng vật sét (kaolinit, illit…), thạch anh hạt bột, mica', 'kv_ok' => [['khoang vat set', 'set', 'kaolinit', 'illit', 'montmorillonit'], ['thach anh', 'mica', 'chat huu co']], 'kv_need' => 1,
            'dd' => 'Hạt mịn, mềm, tách thành phiến mỏng song song mặt lớp; mùi đất khi thấm ướt; màu xám, đen, nâu đỏ', 'dd_ok' => ['tach lop', 'tach phien', 'mem', 'min', 'mui dat', 'phan lop', 'tron'],
            'dk' => 'Bùn, sét lắng đọng ở môi trường nước yên tĩnh (hồ, đầm, đồng bằng ngập lụt, biển sâu)', 'dk_ok' => ['yen tinh', 'ho', 'dam', 'dam lay', 'bien sau', 'nuoc tinh', 'lang dong', 'ngap lut'],
        ],
        'cuoi_ket' => [
            'ten' => 'Cuội kết (Conglomerate)', 'alias' => ['cuoi ket', 'conglomerat', 'conglomerate', 'cuoi soi ket', 'soi ket'],
            'nhom' => 'tram_tich', 'nhom_txt' => 'Đá trầm tích vụn hạt thô',
            'kt' => 'Hạt > 2 mm, tròn cạnh (mài tròn tốt), gắn kết bởi nền cát/sét', 'kt_ok' => ['tron canh', 'mai tron', 'tron', 'hat tho', '2 mm', 'cuoi', 'psephit'],
            'kv' => 'Cuội thạch anh, mảnh đá; nền cát, xi măng silic/calcit/oxit sắt', 'kv_ok' => [['thach anh', 'manh da', 'cuoi', 'quartz'], ['xi mang', 'nen cat', 'cat', 'set', 'calcit']], 'kv_need' => 1,
            'dd' => 'Hạt cuội tròn lớn thấy rõ nằm trong nền hạt nhỏ hơn', 'dd_ok' => ['tron', 'cuoi tron', 'thay ro', 'nen', 'hat lon'],
            'dk' => 'Môi trường năng lượng cao (lòng sông, quạt bồi tích, bãi biển), vận chuyển xa nên tròn cạnh', 'dk_ok' => ['song', 'suoi', 'nang luong cao', 'van chuyen xa', 'bai bien', 'dong chay manh'],
        ],
        'dam_ket' => [
            'ten' => 'Dăm kết (Breccia)', 'alias' => ['dam ket', 'breccia', 'breccie', 'bretxia', 'brechia', 'dam'],
            'nhom' => 'tram_tich', 'nhom_txt' => 'Đá trầm tích vụn hạt thô',
            'kt' => 'Hạt > 2 mm, sắc cạnh (góc cạnh), gắn kết', 'kt_ok' => ['sac canh', 'goc canh', 'canh sac', 'hat tho', '2 mm', 'dam'],
            'kv' => 'Mảnh đá, mảnh khoáng vật sắc cạnh; xi măng', 'kv_ok' => [['manh da', 'manh vun', 'thach anh', 'manh'], ['xi mang', 'nen', 'cat', 'set']], 'kv_need' => 1,
            'dd' => 'Mảnh lớn sắc cạnh, kích thước không đều', 'dd_ok' => ['sac canh', 'goc canh', 'khong deu', 'manh lon'],
            'dk' => 'Vận chuyển ngắn, gần nguồn: chân vách, sườn núi, đới đứt gãy, sụp đổ hang', 'dk_ok' => ['gan nguon', 'van chuyen ngan', 'chan vach', 'suon', 'dut gay', 'sup do'],
        ],
        'da_voi' => [
            'ten' => 'Đá vôi chứa hóa thạch (Limestone)', 'alias' => ['da voi', 'limestone', 'da voi chua hoa thach', 'da voi vo so', 'da voi san ho', 'da voi sinh vat', 'coquina', 'da voi hoa thach'],
            'nhom' => 'tram_tich', 'nhom_txt' => 'Đá trầm tích sinh hóa (hữu cơ) chứa vôi',
            'kt' => 'Kiến trúc sinh vật (vỏ sò ốc, san hô, huệ biển) gắn bằng calcit; hạt mịn – ẩn tinh', 'kt_ok' => ['sinh vat', 'vo sinh vat', 'hoa thach', 'an tinh', 'hat min', 'vun sinh vat', 'trung ca'],
            'kv' => 'Calcit (CaCO₃) chủ yếu', 'kv_ok' => [['calcit', 'caco3', 'canxit', 'voi', 'cacbonat']], 'kv_need' => 1,
            'dd' => 'Sủi bọt mạnh với HCl; thấy hóa thạch (vỏ sò, san hô, huệ biển); dao vạch được', 'dd_ok' => ['sui', 'hcl', 'hoa thach', 'vo so', 'san ho', 'hue bien'],
            'dk' => 'Biển nông, ấm, nước trong; tích tụ vỏ sinh vật (vd Hà Tiên – Kiên Lương)', 'dk_ok' => ['bien nong', 'bien', 'am', 'nuoc trong', 'ran san ho', 'tich tu vo'],
        ],
        'travertin' => [
            'ten' => 'Travertin (đá vôi nước ngọt)', 'alias' => ['travertin', 'travertine', 'tuf voi', 'da voi nuoc ngot', 'tufa'],
            'nhom' => 'tram_tich', 'nhom_txt' => 'Đá trầm tích hóa học (chứa vôi)',
            'kt' => 'Ẩn tinh – tinh thể nhỏ, phân dải (lớp đồng tâm/song song), nhiều lỗ hổng', 'kt_ok' => ['phan dai', 'dai', 'lo hong', 'xop', 'an tinh', 'dong tam', 'lop song song', 'hat min'],
            'kv' => 'Calcit (aragonit)', 'kv_ok' => [['calcit', 'caco3', 'aragonit', 'canxit', 'cacbonat']], 'kv_need' => 1,
            'dd' => 'Sủi bọt HCl; dải màu kem – vàng nâu xen kẽ; nhiều lỗ rỗng', 'dd_ok' => ['sui', 'hcl', 'dai', 'lo rong', 'lo hong', 'kem', 'vang nau'],
            'dk' => 'Kết tủa CaCO₃ từ suối khoáng nóng, nước ngầm, trong hang động khi CO₂ thoát ra', 'dk_ok' => ['ket tua', 'suoi nong', 'suoi khoang', 'hang dong', 'nuoc ngot', 'thach nhu', 'co2 thoat'],
        ],
        'laterit' => [
            'ten' => 'Laterit (đá ong)', 'alias' => ['laterit', 'laterite', 'da ong', 'dat da ong', 'ket von'],
            'nhom' => 'tram_tich', 'nhom_txt' => 'Đá trầm tích (sản phẩm phong hóa – hóa học) chứa sắt, nhôm',
            'kt' => 'Kết vón, cấu tạo tổ ong (lỗ hổng chứa sét)', 'kt_ok' => ['ket von', 'to ong', 'lo hong', 'xop', 'hat dau', 'pisolit'],
            'kv' => 'Hydroxid sắt – nhôm (limonit/goethit, hematit, gibbsit), kaolinit', 'kv_ok' => [['limonit', 'goethit', 'hematit', 'hydroxid sat', 'oxit sat', 'sat', 'fe'], ['nhom', 'gibbsit', 'kaolinit', 'set', 'al']], 'kv_need' => 1,
            'dd' => 'Đỏ nâu, vàng nâu; lỗ rỗng tổ ong; cứng lại khi phơi khô', 'dd_ok' => ['do nau', 'nau do', 'vang nau', 'to ong', 'lo rong', 'ket von', 'cung khi kho'],
            'dk' => 'Phong hóa hóa học mạnh ở khí hậu nhiệt đới ẩm, mực nước ngầm dao động (Củ Chi, Bình Dương)', 'dk_ok' => ['phong hoa', 'nhiet doi', 'am', 'nuoc ngam dao dong', 'dao dong'],
        ],
        'slate' => [
            'ten' => 'Thạch bản – đá bảng (Slate)', 'alias' => ['slate', 'thach ban', 'da ban', 'ardoise', 'da bang'],
            // 'phiến sét' trong giáo trình BMCS là đá sét (shale), nhưng nhiều tài liệu gọi slate là 'đá phiến sét' → khi đáp án bộ mẫu là slate thì chấp nhận.
            'alt_ok' => ['phien set', 'da phien set'],
            'nhom' => 'bien_chat', 'nhom_txt' => 'Đá biến chất khu vực mức thấp (có phiến)',
            'kt' => 'Hạt rất mịn; cấu tạo phiến – tách tấm phẳng mỏng (mặt trần không thấy hạt)', 'kt_ok' => ['phien', 'hat rat min', 'hat min', 'tach tam', 'tam phang', 'an tinh'],
            'kv' => 'Mica vi tinh (sericit), chlorit, thạch anh hạt mịn, khoáng vật sét', 'kv_ok' => [['mica', 'sericit', 'chlorit', 'clorit'], ['thach anh', 'set']], 'kv_need' => 1,
            'dd' => 'Tách thành tấm mỏng phẳng, mặt vỡ phẳng ánh mờ; gõ kêu thanh; xám đen, đen, đỏ, lục', 'dd_ok' => ['tach tam', 'tam mong', 'phang', 'keu thanh', 'go keu', 'xam den', 'den'],
            'dk' => 'Biến chất mức thấp (khu vực) từ sét kết, bùn kết', 'dk_ok' => ['bien chat thap', 'muc thap', 'set ket', 'bun ket', 'khu vuc'],
        ],
        'phyllit' => [
            'ten' => 'Phyllit (đá phiến lục / phiến ánh lụa)', 'alias' => ['phyllit', 'phyllite', 'phillit', 'phylit', 'philit', 'fylit'],
            'nhom' => 'bien_chat', 'nhom_txt' => 'Đá biến chất khu vực mức thấp – trung bình (có phiến)',
            'kt' => 'Hạt mịn (thô hơn slate); phiến gợn sóng', 'kt_ok' => ['phien', 'gon song', 'hat min', 'nhan nheo'],
            'kv' => 'Sericit (mica mịn), chlorit, thạch anh', 'kv_ok' => [['mica', 'sericit', 'chlorit', 'clorit'], ['thach anh']], 'kv_need' => 1,
            'dd' => 'Mặt phiến ánh lụa (óng ánh), gợn sóng; tách phiến mỏng', 'dd_ok' => ['anh lua', 'ong anh', 'lua', 'gon song', 'tach phien'],
            'dk' => 'Biến chất khu vực mức thấp từ slate/sét kết', 'dk_ok' => ['bien chat', 'slate', 'set ket', 'khu vuc', 'muc thap'],
        ],
        'schist' => [
            'ten' => 'Đá phiến kết tinh (Schist, micaschist)', 'alias' => ['schist', 'micaschist', 'da phien', 'phien mica', 'da phien mica', 'phien ket tinh', 'da phien ket tinh', 'sit'],
            'nhom' => 'bien_chat', 'nhom_txt' => 'Đá biến chất khu vực mức trung bình (có phiến)',
            'kt' => 'Hạt vừa – thô; cấu tạo phiến rõ (vảy mica xếp song song)', 'kt_ok' => ['phien', 'hat vua', 'hat tho', 'vay', 'song song', 'schistose'],
            'kv' => 'Mica (muscovit, biotit), thạch anh, ± fenpat, granat', 'kv_ok' => [['mica', 'muscovit', 'biotit'], ['thach anh', 'granat', 'fenpat', 'felspat']], 'kv_need' => 2,
            'dd' => 'Vảy mica lấp lánh nhìn thấy bằng mắt; tách phiến, mặt vỡ xù xì; có thể có hạt granat', 'dd_ok' => ['lap lanh', 'vay mica', 'tach phien', 'granat', 'xu xi', 'choi sang'],
            'dk' => 'Biến chất khu vực mức trung bình (áp suất định hướng + nhiệt) từ sét kết', 'dk_ok' => ['khu vuc', 'ap suat', 'trung binh', 'set ket', 'dinh huong'],
        ],
        'gneiss' => [
            'ten' => 'Gneiss (đá gơ-nai)', 'alias' => ['gneiss', 'gonai', 'go nai', 'gnai', 'gnei', 'gneis', 'da go nai', 'gonais'],
            'nhom' => 'bien_chat', 'nhom_txt' => 'Đá biến chất khu vực mức cao (có phiến – dạng dải)',
            'kt' => 'Hạt vừa – thô; cấu tạo dải (gneiss) sáng – tối xen kẽ', 'kt_ok' => ['dai', 'phan dai', 'bang', 'hat tho', 'hat vua', 'gneis', 'go nai'],
            'kv' => 'Fenpat, thạch anh (dải sáng); biotit, hornblend (dải tối)', 'kv_ok' => [['fenpat', 'felspat', 'feldspar', 'orthocla', 'plagiocla', 'fenspat'], ['thach anh'], ['biotit', 'mica', 'hornblend', 'amphibol']], 'kv_need' => 3,
            'dd' => 'Dải sáng (thạch anh, fenpat) xen dải tối (biotit, hornblend) rõ; không tách phiến mỏng', 'dd_ok' => ['dai sang', 'dai toi', 'sang toi', 'xen ke', 'phan dai', 'van dai'],
            'dk' => 'Biến chất khu vực mức cao (nhiệt độ, áp suất lớn) từ granit hoặc đá trầm tích', 'dk_ok' => ['muc cao', 'bien chat cao', 'nhiet do cao', 'ap suat cao', 'granit', 'khu vuc'],
        ],
        'marble' => [
            'ten' => 'Đá hoa (Marble)', 'alias' => ['da hoa', 'marble', 'cam thach', 'marmor', 'da hoa cuong khong'],
            'nhom' => 'bien_chat', 'nhom_txt' => 'Đá biến chất (không phiến – dạng khối)',
            'kt' => 'Hạt tái kết tinh, khối (không phiến), đều hạt', 'kt_ok' => ['khoi', 'khong phien', 'tai ket tinh', 'hat deu', 'deu hat', 'hat', 'ket tinh'],
            'kv' => 'Calcit (dolomit)', 'kv_ok' => [['calcit', 'caco3', 'dolomit', 'canxit', 'cacbonat']], 'kv_need' => 1,
            'dd' => 'Sủi bọt HCl; hạt calcit cài nhau, mặt cát khai lấp lánh; mềm (dao vạch được); trắng hoặc có vân màu', 'dd_ok' => ['sui', 'hcl', 'lap lanh', 'mat cat khai', 'mem', 'dao vach', 'van', 'trang'],
            'dk' => 'Đá vôi bị biến chất (tiếp xúc hoặc khu vực), tái kết tinh', 'dk_ok' => ['da voi', 'tai ket tinh', 'tiep xuc', 'khu vuc'],
        ],
        'quartzit' => [
            'ten' => 'Quartzit (đá thạch anh)', 'alias' => ['quartzit', 'quartzite', 'quarzit', 'quaczit', 'quarzite', 'da thach anh', 'thach anh bien chat', 'quazit', 'quatzit'],
            'nhom' => 'bien_chat', 'nhom_txt' => 'Đá biến chất (không phiến – dạng khối)',
            'kt' => 'Hạt tái kết tinh cài nhau, khối, không phiến', 'kt_ok' => ['khoi', 'khong phien', 'tai ket tinh', 'cai nhau', 'hat', 'dac sit'],
            'kv' => 'Thạch anh (> 90%)', 'kv_ok' => [['thach anh', 'sio2', 'quartz']], 'kv_need' => 1,
            'dd' => 'Rất cứng (vạch được kính), không sủi HCl; mặt vỡ cắt ngang qua hạt (khác cát kết)', 'dd_ok' => ['rat cung', 'vach duoc kinh', 'vach kinh', 'khong sui', 'cat ngang qua hat', 'vo qua hat', 'cung'],
            'dk' => 'Cát kết thạch anh bị biến chất, tái kết tinh', 'dk_ok' => ['cat ket', 'tai ket tinh', 'bien chat'],
        ],
        'hornfels' => [
            'ten' => 'Đá sừng (Hornfels)', 'alias' => ['hornfels', 'hornfel', 'da sung', 'hocfels'],
            'nhom' => 'bien_chat', 'nhom_txt' => 'Đá biến chất tiếp xúc nhiệt (không phiến)',
            'kt' => 'Hạt mịn, khối đặc sít, không phiến', 'kt_ok' => ['khoi', 'dac sit', 'hat min', 'khong phien', 'an tinh'],
            'kv' => 'Thạch anh, mica (biotit), fenpat, andalusit, cordierit…', 'kv_ok' => [['thach anh', 'biotit', 'mica', 'fenpat', 'andalusit', 'cordierit', 'pyroxen']], 'kv_need' => 1,
            'dd' => 'Sẫm màu, rất cứng và dai, đặc sít, vỡ sắc cạnh (như sừng)', 'dd_ok' => ['sam', 'den', 'cung', 'dai', 'dac sit', 'sac canh', 'sung'],
            'dk' => 'Biến chất tiếp xúc nhiệt quanh khối magma xâm nhập, từ đá sét', 'dk_ok' => ['tiep xuc', 'nhiet', 'xam nhap', 'quanh khoi', 'vanh tiep xuc'],
        ],
    ];

    $kb = ['kv' => $kv, 'da' => $da, 'group_ok' => ['magma' => $MAGMA, 'tram_tich' => $TT, 'bien_chat' => $BC],
           'group_txt' => ['magma' => 'Đá magma', 'tram_tich' => 'Đá trầm tích', 'bien_chat' => 'Đá biến chất']];
    return $kb;
}

/* ---------------- So khớp chữ (bỏ dấu, chữ thường) ---------------- */

function khtd_norm(string $s): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        $groups = ['a' => 'àáạảãâầấậẩẫăằắặẳẵ', 'e' => 'èéẹẻẽêềếệểễ', 'i' => 'ìíịỉĩ', 'o' => 'òóọỏõôồốộổỗơờớợởỡ',
                   'u' => 'ùúụủũưừứựửữ', 'y' => 'ỳýỵỷỹ', 'd' => 'đ'];
        foreach ($groups as $base => $chars) foreach (preg_split('//u', $chars, -1, PREG_SPLIT_NO_EMPTY) as $c) $map[$c] = $base;
        foreach (['₀', '₁', '₂', '₃', '₄', '₅', '₆', '₇', '₈', '₉'] as $i => $c) $map[$c] = (string) $i;
    }
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, $map);
    return $s;
}

/** Chữ đã chuẩn hoá cho so khớp cụm từ: chỉ a-z0-9 cách nhau một khoảng trắng, có đệm hai đầu. */
function khtd_kb_text(string $s): string
{
    return ' ' . trim((string) preg_replace('/[^a-z0-9]+/', ' ', khtd_norm($s))) . ' ';
}

/** Một cụm (có thể "a+b") có xuất hiện trong chữ đã chuẩn hoá không. Công thức hoá học so cả khi viết liền. */
function khtd_kb_hit(string $text, string $token): bool
{
    foreach (explode('+', $token) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        // Khớp trọn từ; cụm dài ≥ 5 ký tự được khớp tiền tố của từ cuối (plagiocla → plagioclas, plagioclase).
        $ok = strpos($text, ' ' . $part . ' ') !== false || (strlen($part) >= 5 && strpos($text, ' ' . $part) !== false);
        if (!$ok && strlen(str_replace(' ', '', $part)) >= 4 && preg_match('/\d/', $part)) {
            $ok = strpos(str_replace(' ', '', $text), str_replace(' ', '', $part)) !== false;
        }
        if (!$ok) return false;
    }
    return true;
}

function khtd_kb_any(string $raw, array $tokens): bool
{
    $t = khtd_kb_text($raw);
    if (trim($t) === '') return false;
    foreach ($tokens as $tok) if (khtd_kb_hit($t, (string) $tok)) return true;
    return false;
}

/** Các số trong chữ (5,5 → 5.5; "5-6" → [5, 6]). */
function khtd_kb_numbers(string $raw): array
{
    $s = preg_replace('/(\d),(\d)/', '$1.$2', khtd_norm($raw));
    preg_match_all('/\d+(?:\.\d+)?/', (string) $s, $m);
    return array_map('floatval', $m[0] ?? []);
}

/** Số ghi có nằm trong khoảng chuẩn (± dung sai) và không số nào lệch quá xa. */
function khtd_kb_range_ok(string $raw, array $range, float $tol, float $far): bool
{
    $nums = array_values(array_filter(khtd_kb_numbers($raw), static fn ($x) => $x > 0 && $x <= 20));
    if (!$nums) return false;
    [$lo, $hi] = $range;
    $hit = false;
    foreach ($nums as $x) {
        if ($x < $lo - $far || $x > $hi + $far) return false;
        if ($x >= $lo - $tol && $x <= $hi + $tol) $hit = true;
    }
    return $hit;
}

/**
 * Tìm khoáng vật/đá theo tên SV ghi. $kind: 'kv' | 'da' | null (tìm cả hai).
 * Trả ['kind','key','entry'] của tên khớp DÀI NHẤT, hoặc null.
 */
function khtd_kb_find(string $name, ?string $kind = null): ?array
{
    $t = khtd_kb_text($name);
    if (trim($t) === '') return null;
    $kb = khtd_kb();
    $best = null; $bestLen = 0;
    foreach ($kind ? [$kind] : ['kv', 'da'] as $k) {
        foreach ($kb[$k] as $key => $e) {
            foreach ($e['alias'] as $a) {
                if (strlen($a) > $bestLen && khtd_kb_hit($t, $a)) { $best = ['kind' => $k, 'key' => $key, 'entry' => $e]; $bestLen = strlen($a); }
            }
        }
    }
    return $best;
}

/** Tóm tắt dữ liệu chuẩn của một mẫu (đưa vào đề chấm Gemini và phần "chuẩn" trong kết quả). */
function khtd_kb_summary(string $kind, string $key): string
{
    $e = khtd_kb()[$kind][$key] ?? null;
    if (!$e) return '';
    if ($kind === 'kv') {
        $h = $e['cung'][0] == $e['cung'][1] ? (string) $e['cung'][0] : $e['cung'][0] . '–' . $e['cung'][1];
        $d = $e['ty_trong'][0] == $e['ty_trong'][1] ? (string) $e['ty_trong'][0] : $e['ty_trong'][0] . '–' . $e['ty_trong'][1];
        return $e['ten'] . ': TPHH ' . $e['tphh'] . '; độ cứng ' . str_replace('.', ',', $h) . '; cát khai: ' . $e['cat_khai'] . '; dạng tinh thể: ' . $e['tinh_the']
            . '; vết vạch: ' . $e['vach'] . '; tỷ trọng ' . str_replace('.', ',', $d) . '; màu: ' . $e['mau'] . '; ánh: ' . $e['anh'] . '; khác: ' . $e['khac'] . '.';
    }
    return $e['ten'] . ': nhóm ' . $e['nhom_txt'] . '; kiến trúc: ' . $e['kt'] . '; khoáng vật chính: ' . $e['kv'] . '; đặc điểm nhận diện: ' . $e['dd'] . '; điều kiện thành tạo: ' . $e['dk'] . '.';
}
