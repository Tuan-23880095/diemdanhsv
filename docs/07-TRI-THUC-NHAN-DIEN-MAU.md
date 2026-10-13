# Tri thức chuẩn chấm nhận diện khoáng vật & đá (diemdanhsv.com)

Dữ liệu chuẩn mà hệ thống dùng để chấm **phiếu luyện thi cuối khóa** (phiếu 7) và **dự báo điểm thi** từ bảng mô tả mẫu của phiếu 1–6. Mã nguồn: `api/lib/khtd_kb.php` (bảng dưới sinh tự động từ đó).

Nguồn đối chiếu: *Tài liệu thực tập Địa chất đại cương* (BM Địa chất cơ sở, Khoa Địa chất, ĐH KHTN TP.HCM, 1999) — bảng các khoáng vật thường gặp, bảng phân loại đá magma, trầm tích, biến chất; *Khoáng vật tạo đá* (Hoàng Văn Hải); *Thạch học đá magma và biến chất* (H. Trung). Các sách đã đưa lên NotebookLM "2026 Khoa học Trái đất".

## Rubric ĐG1.1 (mỗi mẫu 1 điểm)

| Điểm | Đá | Khoáng vật |
|---|---|---|
| 1 | Đúng tên + có số hiệu + đúng nhóm đá + đúng kiến trúc + đủ và đúng khoáng vật chính + đúng đặc điểm nhận diện | Có số hiệu + đúng tên + thành phần hóa học + độ cứng + cát khai + (vết vạch / màu / tính chất khác) |
| 0,75 | Tên + số hiệu + nhóm đá + kiến trúc + khoáng vật chính | Số hiệu + tên + TPHH + độ cứng + cát khai |
| 0,5 | Tên + số hiệu + nhóm đá + kiến trúc | Số hiệu + tên + TPHH + (độ cứng hoặc cát khai) |
| 0,25 | Tên + số hiệu | Số hiệu + tên + (TPHH hoặc độ cứng hoặc cát khai) |
| 0 | Sai tên hoặc không ghi số hiệu | Không ghi số hiệu hoặc sai tên |

Dung sai khi máy đối chiếu: độ cứng ± 0,5 (ghi khoảng quá rộng, lệch > 1,5 thì không tính); thành phần hóa học chấp nhận công thức hoặc mô tả tương đương (vd "silicat K, Al" cho orthoclas); khoáng vật chính của đá cần đủ các nhóm chính (granit, gneiss: 3 nhóm; đa số đá: 1–2 nhóm).

## Khoáng vật (16)

| Khoáng vật | TPHH | Độ cứng | Cát khai | Dạng tinh thể | Vết vạch | Tỷ trọng | Màu | Ánh | Tính chất khác |
|---|---|---|---|---|---|---|---|---|---|
| **Thạch anh (Quartz)** | SiO₂ | 7 | Không có cát khai; vết vỡ vỏ sò (trôn ốc) | Lăng trụ 6 mặt, đầu chóp; sọc ngang trên mặt lăng trụ | Trắng / không màu | 2,6–2,7 | Không màu, trắng sữa, tím (amethyst), hồng, ám khói, xám | Thủy tinh (mỡ trên mặt vỡ) | Vạch được kính; không sủi bọt HCl; trong đá magma có màu xám, ánh mỡ |
| **Orthoclas – fenpat K (Orthoclase)** | KAlSi₃O₈ | 6–6,5 | 2 hướng cát khai tốt, gần vuông góc (90°) | Dạng tấm, lăng trụ ngắn; song tinh Carlsbad | Trắng | 2,55–2,63 | Hồng, hồng thịt, trắng, xám, vàng nhạt | Thủy tinh, xà cừ trên mặt cát khai | Không có sọc song tinh đa hợp (phân biệt plagioclas); mặt cát khai phản chiếu chói |
| **Plagioclas – fenpat Na-Ca (Plagioclase)** | NaAlSi₃O₈ – CaAl₂Si₂O₈ | 6–6,5 | 2 hướng cát khai tốt, xiên góc 86°/94° | Dạng tấm, que dài (trong đá hạt mịn) | Trắng | 2,6–2,76 | Trắng, xám, xám xanh, không màu | Thủy tinh, xà cừ | Có sọc song tinh (đa hợp) song song trên mặt cát khai |
| **Pyroxen – augit (Augite)** | Silicat Ca, Mg, Fe (Al) – (Ca,Mg,Fe)₂Si₂O₆ | 5,5–6 | 2 hướng cát khai gần vuông góc (87°/93°) | Lăng trụ ngắn, mặt cắt ngang 4 hoặc 8 cạnh | Lục xám nhạt / trắng xám | 3,2–3,5 | Lục sẫm, đen lục, đen | Thủy tinh, mờ | Phân biệt hornblend bằng góc cát khai ~90° (hornblend 56°/124°); tinh thể ngắn mập |
| **Hornblend – amphibol (Hornblende)** | Silicat Ca, Na, Mg, Fe, Al ngậm nước (có OH) | 5–6 | 2 hướng cát khai tốt, xiên góc 56°/124° | Lăng trụ dài, mặt cắt ngang 6 cạnh | Trắng xám, lục xám | 3–3,4 | Lục sẫm, đen | Thủy tinh | Tinh thể dài; góc cát khai 56°/124° (phân biệt pyroxen) |
| **Olivin (Olivine)** | (Mg,Fe)₂SiO₄ | 6,5–7 | Cát khai kém / không rõ; vết vỡ vỏ sò | Hạt nhỏ kết khối, khó thấy tinh thể | Trắng / không màu | 3,2–4,4 | Lục ô-liu, lục vàng, vàng nâu | Thủy tinh | Gặp trong bazan, gabro, peridotit (đá mafic – siêu mafic); hạt màu xanh ô-liu |
| **Biotit – mica đen (Biotite)** | K(Mg,Fe)₃AlSi₃O₁₀(OH)₂ – silicat K, Mg, Fe, Al ngậm nước | 2,5–3 | 1 hướng cát khai hoàn toàn, tách thành lá mỏng | Dạng tấm, vảy 6 cạnh | Trắng / lục xám | 2,8–3,2 | Đen, nâu đen, lục sẫm | Thủy tinh – xà cừ (kim cương trên mặt cát khai) | Lá mỏng đàn hồi, móng tay vạch được |
| **Muscovit – mica trắng (Muscovite)** | KAl₂(AlSi₃O₁₀)(OH)₂ – silicat K, Al ngậm nước | 2–2,5 | 1 hướng cát khai hoàn toàn, tách lá mỏng | Dạng tấm, vảy 6 cạnh | Trắng / không màu | 2,76–2,88 | Không màu, trắng bạc, vàng nhạt | Xà cừ, thủy tinh | Lá mỏng trong suốt, đàn hồi |
| **Calcit (Calcite)** | CaCO₃ | 3 | 3 hướng cát khai hoàn toàn (mặt thoi) | Khối thoi, lăng trụ, tháp nhọn (scalenohedral) | Trắng | 2,7–2,72 | Không màu, trắng, vàng, hồng, xám | Thủy tinh | Sủi bọt mạnh với HCl loãng nguội; khúc xạ kép (băng lan) |
| **Talc (hoạt thạch)** | Mg₃Si₄O₁₀(OH)₂ – silicat Mg ngậm nước | 1 | 1 hướng hoàn toàn (vảy, lá) | Khối vảy, dạng lá, khối đặc | Trắng | 2,7–2,8 | Lục táo, trắng, xám, bạc | Xà cừ, mỡ | Sờ trơn như xà phòng; móng tay vạch được; mềm nhất thang Mohs |
| **Thạch cao (Gypsum)** | CaSO₄·2H₂O | 2 | 1 hướng hoàn toàn (+ 2 hướng kém) | Dạng tấm, sợi, hạt; song tinh đuôi én | Trắng | 2,3–2,35 | Không màu, trắng, xám | Thủy tinh, xà cừ, tơ (dạng sợi) | Móng tay vạch được; không sủi HCl (phân biệt calcit) |
| **Fluorit (Fluorite)** | CaF₂ | 4 | 4 hướng hoàn toàn (bát diện) | Lập phương, bát diện | Trắng | 3,1–3,2 | Tím, lục, vàng, xanh, không màu, hồng | Thủy tinh | Phát quang dưới tia cực tím; đồng xu không vạch được, đinh vạch được |
| **Apatit (Apatite)** | Ca₅(PO₄)₃(F,Cl,OH) | 5 | Cát khai kém (xấu) | Lăng trụ 6 cạnh, hạt kết khối | Trắng | 3,1–3,2 | Lục, nâu, xanh, tím, vàng, không màu | Thủy tinh, nhựa | Lưỡi dao vạch được khó; không vạch được kính; nguồn phosphat |
| **Topaz** | Al₂SiO₄(F,OH)₂ | 8 | 1 hướng hoàn toàn (mặt đáy) | Lăng trụ có sọc dọc, đầu chóp nhọn | Trắng / không màu | 3,4–3,6 | Không màu, vàng, hồng, xanh lam nhạt | Thủy tinh | Vạch được thạch anh; nặng hơn thạch anh |
| **Corindon (Corundum; ruby, sapphire)** | Al₂O₃ | 9 | Không cát khai (giả cát khai – tách theo nhiều hướng) | Lăng trụ 6 cạnh, hình thùng, dạng tấm | Trắng / không màu | 3,9–4,1 | Xám, nâu, đỏ (ruby), xanh lam (sapphire) | Kim cương – thủy tinh | Rất cứng (chỉ kém kim cương), nặng; vạch được topaz |
| **Kim cương (Diamond)** | C (carbon) | 10 | 4 hướng hoàn toàn (bát diện) | Bát diện, lập phương | Không có vết vạch (cứng hơn bản sứ) | 3,5–3,53 | Không màu, vàng, nâu, xám | Kim cương | Cứng nhất thang Mohs, vạch được mọi khoáng vật |

## Đá (22)

| Đá | Nhóm | Kiến trúc | Khoáng vật chính | Đặc điểm nhận diện | Điều kiện thành tạo |
|---|---|---|---|---|---|
| **Granit (đá hoa cương)** | Đá magma xâm nhập (sâu), axit – felsic | Hạt thô – vừa, toàn tinh (hiển tinh), đều hạt | Thạch anh, fenpat K (orthoclas) > plagioclas, biotit/muscovit, ít hornblend | Sáng màu (hồng, xám trắng); thấy rõ hạt thạch anh xám ánh mỡ, fenpat hồng/trắng, vảy mica đen | Magma axit kết tinh chậm ở sâu trong vỏ Trái đất (xâm nhập sâu) |
| **Rhyolit (đá lưu vân)** | Đá magma phun trào, axit – felsic | Vi hạt (ẩn tinh) hoặc porphyr (vân ban); có thể có cấu tạo dòng chảy | Thạch anh, fenpat K (ban tinh), nền vi hạt/thủy tinh | Sáng màu (hồng nhạt, xám nhạt, kem), hạt mịn; có thể thấy ban tinh thạch anh/fenpat và vân dòng chảy | Dung nham axit phun trào, nguội nhanh trên mặt đất |
| **Diorit** | Đá magma xâm nhập, trung tính | Hạt vừa – thô, toàn tinh | Plagioclas (Na-Ca) chủ yếu, hornblend, biotit, pyroxen; ít hoặc không thạch anh | Đốm "muối tiêu" trắng – đen xấp xỉ bằng nhau; hầu như không thấy thạch anh | Magma trung tính kết tinh chậm ở sâu (xâm nhập) |
| **Andesit** | Đá magma phun trào, trung tính | Vi hạt hoặc porphyr (ban tinh plagioclas, hornblend trên nền mịn) | Plagioclas, hornblend, pyroxen, biotit | Xám, xám lục, xám nâu; nền mịn với ban tinh plagioclas trắng, que hornblend đen | Dung nham trung tính phun trào ở cung núi lửa (rìa hội tụ), nguội nhanh |
| **Gabro (Gabbro)** | Đá magma xâm nhập, bazơ – mafic | Hạt thô – vừa, toàn tinh | Plagioclas Ca, pyroxen (augit), ± olivin; không có thạch anh | Sẫm màu (đen, lục đen), nặng, hạt thô thấy rõ; không có thạch anh | Magma bazơ kết tinh chậm ở sâu (xâm nhập; vỏ đại dương) |
| **Bazan (Basalt)** | Đá magma phun trào, bazơ – mafic | Vi hạt (ẩn tinh), có thể porphyr; cấu tạo lỗ hổng (lỗ khí), hạnh nhân | Plagioclas Ca, pyroxen, olivin | Đen – xám đen, nặng, hạt mịn; thường có lỗ khí; có thể thấy hạt olivin xanh | Dung nham bazơ phun trào, nguội nhanh (sống núi đại dương, cao nguyên bazan Tây Nguyên) |
| **Obsidian (đá thủy tinh núi lửa)** | Đá magma phun trào, thường axit (giàu SiO₂) | Thủy tinh (không có hạt tinh thể) | Thủy tinh núi lửa giàu SiO₂, không có khoáng vật kết tinh | Đen bóng (có thể nâu), vết vỡ vỏ sò sắc cạnh | Dung nham nguội cực nhanh, không kịp kết tinh |
| **Đá bọt (Pumice)** | Đá magma phun trào (vụn núi lửa), thường axit | Thủy tinh xốp, nhiều lỗ hổng (bọt) | Thủy tinh núi lửa (giàu SiO₂) | Rất nhẹ, nổi trên nước; sáng màu; dày đặc lỗ khí | Magma giàu khí phun nổ, khí thoát nhanh khi nguội cực nhanh |
| **Cát kết (sa thạch)** | Đá trầm tích vụn (cơ học) | Hạt cát 1/16 – 2 mm (0,063–2 mm), gắn kết bởi xi măng | Thạch anh (chủ yếu), fenpat, mảnh đá; xi măng silic, calcit, oxit sắt | Sờ nhám tay, thấy hạt cát; phân lớp (lớp xiên); màu đa dạng | Cát tích tụ ở sông, bãi biển, cồn cát rồi bị nén, gắn kết |
| **Bùn – sét kết (Shale, mudstone)** | Đá trầm tích vụn hạt mịn (pelit) | Hạt rất mịn < 1/16 mm (không thấy hạt bằng mắt); cấu tạo phân lớp mỏng | Khoáng vật sét (kaolinit, illit…), thạch anh hạt bột, mica | Hạt mịn, mềm, tách thành phiến mỏng song song mặt lớp; mùi đất khi thấm ướt; màu xám, đen, nâu đỏ | Bùn, sét lắng đọng ở môi trường nước yên tĩnh (hồ, đầm, đồng bằng ngập lụt, biển sâu) |
| **Cuội kết (Conglomerate)** | Đá trầm tích vụn hạt thô | Hạt > 2 mm, tròn cạnh (mài tròn tốt), gắn kết bởi nền cát/sét | Cuội thạch anh, mảnh đá; nền cát, xi măng silic/calcit/oxit sắt | Hạt cuội tròn lớn thấy rõ nằm trong nền hạt nhỏ hơn | Môi trường năng lượng cao (lòng sông, quạt bồi tích, bãi biển), vận chuyển xa nên tròn cạnh |
| **Dăm kết (Breccia)** | Đá trầm tích vụn hạt thô | Hạt > 2 mm, sắc cạnh (góc cạnh), gắn kết | Mảnh đá, mảnh khoáng vật sắc cạnh; xi măng | Mảnh lớn sắc cạnh, kích thước không đều | Vận chuyển ngắn, gần nguồn: chân vách, sườn núi, đới đứt gãy, sụp đổ hang |
| **Đá vôi chứa hóa thạch (Limestone)** | Đá trầm tích sinh hóa (hữu cơ) chứa vôi | Kiến trúc sinh vật (vỏ sò ốc, san hô, huệ biển) gắn bằng calcit; hạt mịn – ẩn tinh | Calcit (CaCO₃) chủ yếu | Sủi bọt mạnh với HCl; thấy hóa thạch (vỏ sò, san hô, huệ biển); dao vạch được | Biển nông, ấm, nước trong; tích tụ vỏ sinh vật (vd Hà Tiên – Kiên Lương) |
| **Travertin (đá vôi nước ngọt)** | Đá trầm tích hóa học (chứa vôi) | Ẩn tinh – tinh thể nhỏ, phân dải (lớp đồng tâm/song song), nhiều lỗ hổng | Calcit (aragonit) | Sủi bọt HCl; dải màu kem – vàng nâu xen kẽ; nhiều lỗ rỗng | Kết tủa CaCO₃ từ suối khoáng nóng, nước ngầm, trong hang động khi CO₂ thoát ra |
| **Laterit (đá ong)** | Đá trầm tích (sản phẩm phong hóa – hóa học) chứa sắt, nhôm | Kết vón, cấu tạo tổ ong (lỗ hổng chứa sét) | Hydroxid sắt – nhôm (limonit/goethit, hematit, gibbsit), kaolinit | Đỏ nâu, vàng nâu; lỗ rỗng tổ ong; cứng lại khi phơi khô | Phong hóa hóa học mạnh ở khí hậu nhiệt đới ẩm, mực nước ngầm dao động (Củ Chi, Bình Dương) |
| **Thạch bản – đá bảng (Slate)** | Đá biến chất khu vực mức thấp (có phiến) | Hạt rất mịn; cấu tạo phiến – tách tấm phẳng mỏng (mặt trần không thấy hạt) | Mica vi tinh (sericit), chlorit, thạch anh hạt mịn, khoáng vật sét | Tách thành tấm mỏng phẳng, mặt vỡ phẳng ánh mờ; gõ kêu thanh; xám đen, đen, đỏ, lục | Biến chất mức thấp (khu vực) từ sét kết, bùn kết |
| **Phyllit (đá phiến lục / phiến ánh lụa)** | Đá biến chất khu vực mức thấp – trung bình (có phiến) | Hạt mịn (thô hơn slate); phiến gợn sóng | Sericit (mica mịn), chlorit, thạch anh | Mặt phiến ánh lụa (óng ánh), gợn sóng; tách phiến mỏng | Biến chất khu vực mức thấp từ slate/sét kết |
| **Đá phiến kết tinh (Schist, micaschist)** | Đá biến chất khu vực mức trung bình (có phiến) | Hạt vừa – thô; cấu tạo phiến rõ (vảy mica xếp song song) | Mica (muscovit, biotit), thạch anh, ± fenpat, granat | Vảy mica lấp lánh nhìn thấy bằng mắt; tách phiến, mặt vỡ xù xì; có thể có hạt granat | Biến chất khu vực mức trung bình (áp suất định hướng + nhiệt) từ sét kết |
| **Gneiss (đá gơ-nai)** | Đá biến chất khu vực mức cao (có phiến – dạng dải) | Hạt vừa – thô; cấu tạo dải (gneiss) sáng – tối xen kẽ | Fenpat, thạch anh (dải sáng); biotit, hornblend (dải tối) | Dải sáng (thạch anh, fenpat) xen dải tối (biotit, hornblend) rõ; không tách phiến mỏng | Biến chất khu vực mức cao (nhiệt độ, áp suất lớn) từ granit hoặc đá trầm tích |
| **Đá hoa (Marble)** | Đá biến chất (không phiến – dạng khối) | Hạt tái kết tinh, khối (không phiến), đều hạt | Calcit (dolomit) | Sủi bọt HCl; hạt calcit cài nhau, mặt cát khai lấp lánh; mềm (dao vạch được); trắng hoặc có vân màu | Đá vôi bị biến chất (tiếp xúc hoặc khu vực), tái kết tinh |
| **Quartzit (đá thạch anh)** | Đá biến chất (không phiến – dạng khối) | Hạt tái kết tinh cài nhau, khối, không phiến | Thạch anh (> 90%) | Rất cứng (vạch được kính), không sủi HCl; mặt vỡ cắt ngang qua hạt (khác cát kết) | Cát kết thạch anh bị biến chất, tái kết tinh |
| **Đá sừng (Hornfels)** | Đá biến chất tiếp xúc nhiệt (không phiến) | Hạt mịn, khối đặc sít, không phiến | Thạch anh, mica (biotit), fenpat, andalusit, cordierit… | Sẫm màu, rất cứng và dai, đặc sít, vỡ sắc cạnh (như sừng) | Biến chất tiếp xúc nhiệt quanh khối magma xâm nhập, từ đá sét |

## Tên gọi được chấp nhận (một phần)

- Thạch anh (Quartz): thach anh, quartz, quart, thach anh tim, amethyst, thach anh am khoi, thach anh hong, thach anh trang
- Orthoclas – fenpat K (Orthoclase): orthoclas, orthoclase, orthocla, octocla, octoclaz, ortoclas, orthoclaz, fenpat k
- Plagioclas – fenpat Na-Ca (Plagioclase): plagioclas, plagioclase, plagiocla, plagioclaz, fenpat na, felspat na, fenpat plagiocla, fenpat natri
- Pyroxen – augit (Augite): pyroxen, pyroxene, piroxen, augit, augite, ogit, piroxene
- Hornblend – amphibol (Hornblende): hornblend, hornblende, horblend, amphibol, amphibole, amfibol, hocblen
- Olivin (Olivine): olivin, olivine, peridot, oliven
- Biotit – mica đen (Biotite): biotit, biotite, mica den, bi otit
- Muscovit – mica trắng (Muscovite): muscovit, muscovite, mica trang, moscovit, muscovid
- Calcit (Calcite): calcit, calcite, canxit, can xit, calxit, dai phuong thach, spar
- Talc (hoạt thạch): talc, tan, hoat thach, hoat khoang, talk
- Thạch cao (Gypsum): thach cao, gypsum, gyps, thach cao tinh the, selenit
- Fluorit (Fluorite): fluorit, fluorite, florit, fluorin, huynh thach
- Apatit (Apatite): apatit, apatite
- Topaz: topaz, hoang ngoc, topa
- Corindon (Corundum; ruby, sapphire): corindon, corundum, corindom, corund, ruby, rubi, sapphire, saphia
- Kim cương (Diamond): kim cuong, diamond
- Granit (đá hoa cương): granit, granite, hoa cuong, da hoa cuong, granit biotit, granit porphyr
- Rhyolit (đá lưu vân): rhyolit, rhyolite, ryolit, riolit, riolite, luu van, da luu van
- Diorit: diorit, diorite, dioryt
- Andesit: andesit, andesite, andezit, an son, da an son
- Gabro (Gabbro): gabro, gabbro, gabrro, trong lap
- Bazan (Basalt): bazan, basalt, bazalt, basan, huyen vu, da bazan
- Obsidian (đá thủy tinh núi lửa): obsidian, obsidien, obsidan, obsidiant, da thuy tinh, thuy tinh nui lua, da vo chai
- Đá bọt (Pumice): da bot, pumice, pumic, da bot nui lua, da nhe, da bot bien, bot nui lua
- Cát kết (sa thạch): cat ket, sa thach, sandstone, da cat, arkose, arkoz, grauvac, greywacke
- Bùn – sét kết (Shale, mudstone): bun ket, set ket, bun set ket, phien set, da phien set, shale, mudstone, argillit
- Cuội kết (Conglomerate): cuoi ket, conglomerat, conglomerate, cuoi soi ket, soi ket
- Dăm kết (Breccia): dam ket, breccia, breccie, bretxia, brechia, dam
- Đá vôi chứa hóa thạch (Limestone): da voi, limestone, da voi chua hoa thach, da voi vo so, da voi san ho, da voi sinh vat, coquina, da voi hoa thach
- Travertin (đá vôi nước ngọt): travertin, travertine, tuf voi, da voi nuoc ngot, tufa
- Laterit (đá ong): laterit, laterite, da ong, dat da ong, ket von
- Thạch bản – đá bảng (Slate): slate, thach ban, da ban, ardoise, da bang
- Phyllit (đá phiến lục / phiến ánh lụa): phyllit, phyllite, phillit, phylit, philit, fylit
- Đá phiến kết tinh (Schist, micaschist): schist, micaschist, da phien, phien mica, da phien mica, phien ket tinh, da phien ket tinh, sit
- Gneiss (đá gơ-nai): gneiss, gonai, go nai, gnai, gnei, gneis, da go nai, gonais
- Đá hoa (Marble): da hoa, marble, cam thach, marmor, da hoa cuong khong
- Quartzit (đá thạch anh): quartzit, quartzite, quarzit, quaczit, quarzite, da thach anh, thach anh bien chat, quazit
- Đá sừng (Hornfels): hornfels, hornfel, da sung, hocfels

## Phản biện rubric và bảng nhận diện (đề xuất để giảng viên xem xét)

1. **Bảng nhận diện khoáng vật không có cột "Tên khoáng vật"**, trong khi rubric bắt buộc "viết đúng tên khoáng vật". Sinh viên dễ bỏ sót tên → 0 điểm oan. Đề xuất thêm cột TÊN KHOÁNG VẬT (bản online đã có ô này).
2. **Bất đối xứng giữa đá và khoáng vật ở mức 0,25**: đá chỉ cần tên + số hiệu; khoáng vật cần thêm ít nhất một trong TPHH/độ cứng/cát khai. Nếu không cố ý, nên thống nhất (hệ thống đang chấm đúng nguyên văn rubric: khoáng vật chỉ có tên + số hiệu → 0).
3. **Rubric dạng bậc thang, "cổng" cứng**: đá viết sai kiến trúc nhưng đúng khoáng vật chính và đặc điểm nhận diện vẫn chỉ 0,25; khoáng vật sai TPHH nhưng đúng độ cứng + cát khai chỉ 0,25. Với sinh viên ngành Kinh tế đất đai, cân nhắc chấm cộng dồn (vd tên + số hiệu 0,25; mỗi nhóm tính chất đúng +0,25, tối đa 1).
4. **Cột không được chấm**: "Điều kiện thành tạo" (đá), "Dạng tinh thể", "Tỷ trọng", "Ánh" (khoáng vật) có trong bảng nhưng rubric không tính điểm riêng — tốn thời gian trong 30 phút. Hoặc đưa vào rubric, hoặc ghi rõ "không bắt buộc".
5. **"Nhóm đá" chưa định nghĩa mức chi tiết**: chỉ cần magma / trầm tích / biến chất, hay phải đúng phụ nhóm (xâm nhập – phun trào; axit – bazơ)? Hệ thống đang chấm theo nhóm lớn và chỉ ghi lưu ý khi sai phụ nhóm.
6. **"Có viết số hiệu mẫu"** chỉ xét có/không. Nếu số hiệu ghi sai mẫu thì tên cũng lệch → nên ghi "viết đúng số hiệu". Trang giảng viên đã có ô "Đáp án bộ mẫu" để kiểm tra số hiệu ↔ tên.
7. **Tên gọi tiếng Việt mơ hồ**: "phiến sét" trong giáo trình BMCS là đá sét (shale – trầm tích), nhưng nhiều tài liệu gọi slate là "đá phiến sét"; một bảng tri thức khác trong dự án còn gọi slate là "Phiến sét". Đề xuất chốt tên chuẩn: slate = *thạch bản / đá bảng*; shale = *sét kết / phiến sét (trầm tích)*. Hệ thống chấp nhận "phiến sét" cho slate khi đáp án bộ mẫu ghi slate.
8. **Đề cương**: mục "Đá biến chất" chưa liệt kê mẫu; hệ thống dùng 7 mẫu của phiếu 4 (slate, phyllit, schist, gneiss, đá hoa, quartzit, đá sừng). Tỷ lệ khoáng vật/đá trong 10 mẫu thi cũng chưa quy định.
9. **Sai lệch nhỏ trong giáo trình BMCS (1999)**: mica ghi "ánh kim cương" (thường mô tả ánh thủy tinh – xà cừ); pyroxen ghi độ cứng 5–6 (tài liệu khác 5,5–6). Hệ thống chấp nhận cả hai cách ghi.
