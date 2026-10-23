/* deck-engine.js - Xử lý hiển thị slide, render components, phím tắt, tương tác */
(function() {
  'use strict';

  var LOGO_HCMUS = 'https://firebasestorage.googleapis.com/v0/b/link-anh-web.firebasestorage.app/o/Logo-chinh-hcmus.png?alt=media&token=9cf3a1de-75f1-493b-8711-fd22c7b98738';
  var LOGO_SEN = 'assets/logo-sen.svg';

  var state = {
    lessons: [],
    currentLessonIndex: 0,
    currentSlideIndex: 0,
    slides: []
  };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // Khởi động
  async function init() {
    try {
      if (window.SLIDES_DATA) {
        state.lessons = window.SLIDES_DATA;
      } else {
        var resp = await fetch('slidesData.json');
        state.lessons = await resp.json();
      }
      
      // Lắng nghe phản hồi trực tiếp từ điện thoại sinh viên (BroadcastChannel)
      setupLiveListener();
      
      // Đọc URL hash hoặc query ?lesson=4-1#1
      var params = new URLSearchParams(window.location.search);
      var lessonCode = params.get('lesson') || '4-1';
      var foundIdx = state.lessons.findIndex(l => l.code === lessonCode);
      if (foundIdx >= 0) state.currentLessonIndex = foundIdx;

      buildLessonSelector();
      loadLesson(state.currentLessonIndex);

      window.addEventListener('resize', autoScale);
      autoScale();

      setupKeyboard();
    } catch (e) {
      console.error('Không tải được slidesData.json:', e);
      document.getElementById('scaler').innerHTML = '<div style="padding:40px;text-align:center;font-size:24px;color:red">Lỗi tải dữ liệu bài giảng slidesData.json!</div>';
    }
  }

  function autoScale() {
    var scaler = document.getElementById('scaler');
    if (!scaler) return;
    var w = 1600, h = 900;
    var scale = Math.min(window.innerWidth / w, window.innerHeight / h);
    scaler.style.transform = 'scale(' + scale + ')';
  }

  function buildLessonSelector() {
    var sel = document.getElementById('lessonSelect');
    if (!sel) return;
    sel.innerHTML = state.lessons.map(function(l, i) {
      return '<option value="' + i + '" ' + (i === state.currentLessonIndex ? 'selected' : '') + '>Mục ' + l.code + ' - ' + l.title + '</option>';
    }).join('');
    sel.addEventListener('change', function() {
      loadLesson(parseInt(this.value, 10));
    });
  }

  function loadLesson(idx) {
    state.currentLessonIndex = idx;
    var lesson = state.lessons[idx];
    document.title = 'Mục ' + lesson.code + ' · ' + lesson.title + ' - Đá & Khoáng Vật (LEC10001)';
    state.slides = lesson.slides;
    state.currentSlideIndex = 0;

    // Check hash
    if (window.location.hash) {
      var h = parseInt(window.location.hash.replace('#', ''), 10);
      if (!isNaN(h) && h >= 1 && h <= state.slides.length) {
        state.currentSlideIndex = h - 1;
      }
    }
    renderSlide();
    updateOverviewModal();
  }

  function renderSlide() {
    var lesson = state.lessons[state.currentLessonIndex];
    var sl = state.slides[state.currentSlideIndex];
    var scaler = document.getElementById('scaler');
    var pos = document.getElementById('pos');
    var progress = document.getElementById('progress');

    var slideEl = document.createElement('div');
    slideEl.className = 'slide enter';

    // Nội dung chính
    var bodyHtml = '';
    var kicker = 'Mục ' + lesson.code + ' · ' + lesson.title;
    var title = sl.title || '';
    var sub = sl.sub || '';

    switch (sl.type) {
      case 'title':
        bodyHtml = renderTitleSlide(lesson, sl);
        break;
      case 'obj':
        bodyHtml = renderObjSlide(lesson, sl);
        break;
      case 'fig':
        bodyHtml = renderFigSlide(sl);
        break;
      case 'figbul':
        bodyHtml = renderFigBulSlide(sl);
        break;
      case 'cards':
        bodyHtml = renderCardsSlide(sl);
        break;
      case 'table':
        bodyHtml = renderTableSlide(sl);
        break;
      case 'eq':
        bodyHtml = renderEqSlide(sl);
        break;
      case 'stats':
        bodyHtml = renderStatsSlide(sl);
        break;
      case 'steps':
        bodyHtml = renderStepsSlide(sl);
        break;
      case 'act':
        bodyHtml = renderActSlide(sl);
        break;
      case 'quiz':
        bodyHtml = renderQuizSlide(sl);
        break;
      case 'sum':
        bodyHtml = renderSumSlide(sl);
        break;
      default:
        bodyHtml = '<div class="s-body"><h2 class="s-title">' + esc(title) + '</h2></div>';
    }

    // Chân trang
    var footHtml = '';
    if (sl.type !== 'title') {
      footHtml = '<footer class="s-foot">' +
        '<img class="hcmus" src="' + LOGO_HCMUS + '" alt="Trường ĐH KHTN">' +
        '<span style="font-weight:600">ĐH KHTN ĐHQG-HCM · Khoa Địa chất</span>' +
        '<span class="grow" style="text-align:center;font-style:italic">' + esc(lesson.chapter) + '</span>' +
        '<span class="dqt-sen dqt-logo-o" style="width:36px;height:36px;padding:3px"><img src="' + LOGO_SEN + '" alt="Logo hoa sen đọng sương" onerror="this.parentElement.style.display=\'none\'"></span>' +
        '<span style="font-weight:600">ThS. Đinh Quốc Tuấn</span>' +
        '<span class="num">' + (state.currentSlideIndex + 1) + ' / ' + state.slides.length + '</span>' +
        '</footer>';
    }

    slideEl.innerHTML = '<div class="s-body">' + bodyHtml + '</div>' + footHtml;
    scaler.innerHTML = '';
    scaler.appendChild(slideEl);

    // Cập nhật URL Hash và vị trí
    window.location.hash = '#' + (state.currentSlideIndex + 1);
    if (pos) pos.innerText = (state.currentSlideIndex + 1) + ' / ' + state.slides.length;
    if (progress) {
      var pct = ((state.currentSlideIndex + 1) / state.slides.length) * 100;
      progress.style.width = pct + '%';
    }

    // Tự động render MathJax nếu có
    if (window.MathJax && window.MathJax.typesetPromise) {
      window.MathJax.typesetPromise([slideEl]).catch(function(err){ console.log(err); });
    }
  }

  // --- Render các loại slide ---
  function renderTitleSlide(lesson, sl) {
    return '<div class="cover-wrap">' +
      '<div>' +
        '<div class="logos">' +
          '<img class="hcmus" src="' + LOGO_HCMUS + '" alt="HCMUS">' +
          '<span class="sep"></span>' +
          '<span class="dqt-sen dqt-logo-o" style="width:72px;height:72px;padding:6px"><img src="' + LOGO_SEN + '" alt="Logo hoa sen đọng sương"></span>' +
        '</div>' +
        '<p class="s-kicker" style="font-size:22px">Học phần Đá và khoáng vật (LEC10001)</p>' +
        '<h1 class="cover-h1">Mục ' + lesson.code + '<br>' + esc(lesson.title) + '</h1>' +
        '<p class="cover-sub">' + esc(lesson.chapter) + '</p>' +
        '<div class="people">' +
          '<div class="person"><span class="av" style="background:var(--lam)">QT</span><div><b>ThS. Đinh Quốc Tuấn</b><span>Khoa Địa chất · Bộ môn Thạch học &amp; KS</span></div></div>' +
        '</div>' +
        '<p class="cover-when">Trường ĐH Khoa học Tự nhiên, ĐHQG-HCM · Năm học 2026–2027</p>' +
      '</div>' +
      '<div>' +
        '<div class="join-box">' +
          '<img class="qr" src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' + encodeURIComponent(window.location.href) + '" alt="QR Code">' +
          '<b>Quét mã mở bài học</b>' +
          '<code>diemdanhsv.com</code>' +
          '<p style="font-size:16px;color:var(--mute);margin:0">Mở trên điện thoại/laptop để tham gia Quiz &amp; Tương tác</p>' +
        '</div>' +
      '</div>' +
    '</div>';
  }

  function renderObjSlide(lesson, sl) {
    var itemsHtml = (sl.items || []).map(function(it, idx) {
      var colors = ['var(--luc)', 'var(--lam)', 'var(--tho)', 'var(--son)', 'var(--tim)'];
      var c = colors[idx % colors.length];
      return '<div class="card" style="display:grid;grid-template-columns:180px 1fr;gap:20px;align-items:center;padding:18px 24px">' +
        '<span style="background:' + c + ';color:#fff;font-weight:700;font-size:20px;text-align:center;padding:8px 12px;border-radius:10px">' + esc(it.b) + '</span>' +
        '<span style="font-size:23px;font-weight:500">' + esc(it.t) + '</span>' +
      '</div>';
    }).join('');

    return '<p class="s-kicker">Mục ' + lesson.code + ' · Chuẩn đầu ra</p>' +
      '<h2 class="s-title">Mục tiêu học tập</h2>' +
      '<p class="s-sub">Sau khi hoàn thành mục này, sinh viên có khả năng:</p>' +
      '<div style="display:grid;gap:16px;margin-top:10px">' + itemsHtml + '</div>';
  }

  function renderFigSlide(sl) {
    var imgPath = 'assets/' + sl.fig + '.svg';
    var fallbackPng = 'assets/' + sl.fig + '.png';
    return '<p class="s-kicker">Sơ đồ &amp; Bản đồ tư duy</p>' +
      '<h2 class="s-title">' + esc(sl.title) + '</h2>' +
      (sl.sub ? '<p class="s-sub">' + esc(sl.sub) + '</p>' : '') +
      '<div class="fig-img-box" style="height:550px">' +
        '<img src="' + imgPath + '" onerror="this.src=\'' + fallbackPng + '\'" alt="' + esc(sl.title) + '">' +
      '</div>' +
      (sl.cite ? '<p class="src">' + esc(sl.cite) + '</p>' : '');
  }

  function renderFigBulSlide(sl) {
    var imgPath = 'assets/' + sl.fig + '.svg';
    var fallbackPng = 'assets/' + sl.fig + '.png';
    var bulletsHtml = (sl.bullets || []).map(function(b) {
      var isObj = typeof b === 'object';
      var text = isObj ? b.t : b;
      var isBold = isObj && b.b;
      return '<li class="' + (isBold ? 'bold' : '') + '">' + esc(text) + '</li>';
    }).join('');

    var isRev = sl.figLeft;
    var leftContent = '<div class="fig-img-box"><img src="' + imgPath + '" onerror="this.src=\'' + fallbackPng + '\'" alt="' + esc(sl.title) + '"></div>';
    var rightContent = '<div style="display:flex;flex-direction:column;justify-content:center"><ul class="clean">' + bulletsHtml + '</ul></div>';

    return '<p class="s-kicker">Cơ chế &amp; Quá trình</p>' +
      '<h2 class="s-title">' + esc(sl.title) + '</h2>' +
      (sl.sub ? '<p class="s-sub">' + esc(sl.sub) + '</p>' : '') +
      '<div class="fig-split ' + (isRev ? 'reverse' : '') + '">' +
        (isRev ? rightContent + leftContent : leftContent + rightContent) +
      '</div>' +
      (sl.cite ? '<p class="src">' + esc(sl.cite) + '</p>' : '');
  }

  function renderCardsSlide(sl) {
    var cards = sl.cards || [];
    var cols = cards.length <= 2 ? 'g2' : (cards.length === 3 ? 'g3' : 'g4');
    var cardsHtml = cards.map(function(c, i) {
      var lines = (c.t || []).map(function(t) { return '<li>' + esc(t) + '</li>'; }).join('');
      return '<div class="card" style="border-top:6px solid var(--luc);display:flex;flex-direction:column">' +
        '<span class="tag">Nhóm ' + (i + 1) + '</span>' +
        '<h3>' + esc(c.h) + '</h3>' +
        '<ul class="clean" style="gap:8px;font-size:20px">' + lines + '</ul>' +
      '</div>';
    }).join('');

    return '<p class="s-kicker">Phân loại &amp; So sánh</p>' +
      '<h2 class="s-title">' + esc(sl.title) + '</h2>' +
      (sl.sub ? '<p class="s-sub">' + esc(sl.sub) + '</p>' : '') +
      '<div class="' + cols + '" style="margin-top:14px;flex:1">' + cardsHtml + '</div>' +
      (sl.cite ? '<p class="src">' + esc(sl.cite) + '</p>' : '');
  }

  function renderTableSlide(sl) {
    var headHtml = '<tr>' + (sl.head || []).map(function(h) { return '<th>' + esc(h) + '</th>'; }).join('') + '</tr>';
    var rowsHtml = (sl.rows || []).map(function(r) {
      return '<tr>' + r.map(function(c) { return '<td>' + esc(c) + '</td>'; }).join('') + '</tr>';
    }).join('');

    return '<p class="s-kicker">Bảng số liệu tổng hợp</p>' +
      '<h2 class="s-title">' + esc(sl.title) + '</h2>' +
      (sl.sub ? '<p class="s-sub">' + esc(sl.sub) + '</p>' : '') +
      '<div style="flex:1;overflow:auto">' +
        '<table class="styled-tbl">' +
          '<thead>' + headHtml + '</thead>' +
          '<tbody>' + rowsHtml + '</tbody>' +
        '</table>' +
      '</div>' +
      (sl.cite ? '<p class="src">' + esc(sl.cite) + '</p>' : '');
  }

  function renderEqSlide(sl) {
    var eqsHtml = (sl.eqs || []).map(function(e) {
      return '<div class="eq-box">' + esc(e) + '</div>';
    }).join('');

    var bulletsHtml = (sl.bullets || []).map(function(b) {
      var isObj = typeof b === 'object';
      var text = isObj ? b.t : b;
      var isBold = isObj && b.b;
      return '<li class="' + (isBold ? 'bold' : '') + '">' + esc(text) + '</li>';
    }).join('');

    return '<p class="s-kicker">Phản ứng &amp; Định lượng</p>' +
      '<h2 class="s-title">' + esc(sl.title) + '</h2>' +
      (sl.sub ? '<p class="s-sub">' + esc(sl.sub) + '</p>' : '') +
      '<div class="g2" style="align-items:center;flex:1">' +
        '<div>' + eqsHtml + '</div>' +
        '<div><ul class="clean">' + bulletsHtml + '</ul></div>' +
      '</div>' +
      (sl.cite ? '<p class="src">' + esc(sl.cite) + '</p>' : '');
  }

  function renderStatsSlide(sl) {
    var statsHtml = (sl.stats || []).map(function(st) {
      return '<div class="stat-box">' +
        '<div class="num">' + esc(st.v) + '</div>' +
        '<div class="lbl">' + esc(st.l) + '</div>' +
      '</div>';
    }).join('');

    var bulletsHtml = (sl.bullets || []).map(function(b) {
      return '<li>' + esc(b) + '</li>';
    }).join('');

    return '<p class="s-kicker">Số liệu quan trọng</p>' +
      '<h2 class="s-title">' + esc(sl.title) + '</h2>' +
      (sl.sub ? '<p class="s-sub">' + esc(sl.sub) + '</p>' : '') +
      '<div class="g3" style="margin-bottom:24px">' + statsHtml + '</div>' +
      '<div class="card"><ul class="clean">' + bulletsHtml + '</ul></div>' +
      (sl.cite ? '<p class="src">' + esc(sl.cite) + '</p>' : '');
  }

  function renderStepsSlide(sl) {
    var stepsHtml = (sl.steps || []).map(function(st, idx) {
      return '<div class="card" style="display:grid;grid-template-columns:60px 180px 1fr;gap:18px;align-items:center;padding:14px 22px">' +
        '<div style="width:48px;height:48px;border-radius:50%;background:var(--lam);color:#fff;display:grid;place-items:center;font:700 22px var(--serif)">' + (idx + 1) + '</div>' +
        '<b style="font-size:24px;color:var(--lam)">' + esc(st.h) + '</b>' +
        '<span style="font-size:21px">' + esc(st.t) + '</span>' +
      '</div>';
    }).join('');

    return '<p class="s-kicker">Quy trình thực hành</p>' +
      '<h2 class="s-title">' + esc(sl.title) + '</h2>' +
      '<div style="display:grid;gap:12px;flex:1;justify-content:center">' + stepsHtml + '</div>' +
      (sl.foot ? '<p style="color:var(--luc);font-weight:600;margin-top:12px">' + esc(sl.foot) + '</p>' : '');
  }

  function renderActSlide(sl) {
    var bulletsHtml = (sl.bullets || []).map(function(b) {
      return '<li>' + esc(b) + '</li>';
    }).join('');

    return '<p class="s-kicker">Hoạt động trên lớp</p>' +
      '<h2 class="s-title">' + esc(sl.title) + '</h2>' +
      (sl.sub ? '<p class="s-sub">' + esc(sl.sub) + '</p>' : '') +
      '<div style="display:flex;flex-direction:column;gap:24px;flex:1;justify-content:center">' +
        '<div class="act-card">' +
          '<div class="act-time">' + esc(sl.time || '10\'') + '</div>' +
          '<div>' +
            '<h3 style="font:700 32px var(--serif);margin:0 0 10px;color:var(--luc)">Thảo luận nhóm &amp; Báo cáo</h3>' +
            '<ul class="clean">' + bulletsHtml + '</ul>' +
          '</div>' +
        '</div>' +
      '</div>';
  }

  function renderQuizSlide(sl) {
    var qsHtml = (sl.qs || []).map(function(q, qIdx) {
      var optsHtml = (q.o || []).map(function(opt, oIdx) {
        var letter = 'ABCD'[oIdx];
        var isCorrect = (letter === q.a);
        return '<button type="button" class="quiz-opt-btn" onclick="checkQuizAnswer(this, ' + isCorrect + ')">' +
          '<b style="width:28px;height:28px;border-radius:50%;background:#fff;border:1px solid var(--line);display:grid;place-items:center;font-size:16px">' + letter + '</b>' +
          '<span>' + esc(opt) + '</span>' +
        '</button>';
      }).join('');

      return '<div class="quiz-item">' +
        '<div class="quiz-q">' +
          '<span class="q-badge">Câu ' + (qIdx + 1) + '</span>' +
          '<span>' + esc(q.q) + '</span>' +
        '</div>' +
        '<div class="quiz-opts">' + optsHtml + '</div>' +
      '</div>';
    }).join('');

    return '<p class="s-kicker">Kiểm tra nhanh</p>' +
      '<h2 class="s-title">Quiz tương tác trực tiếp</h2>' +
      '<p class="s-sub">Sinh viên chọn đáp án trực tiếp trên màn hình hoặc qua điện thoại:</p>' +
      '<div class="quiz-container">' + qsHtml + '</div>';
  }

  function renderSumSlide(sl) {
    var pointsHtml = (sl.points || []).map(function(p) {
      return '<li><b>' + esc(p) + '</b></li>';
    }).join('');

    var refsHtml = (sl.refs || []).map(function(r) {
      return '<li style="font-size:18px;color:var(--mute)">' + esc(r) + '</li>';
    }).join('');

    return '<p class="s-kicker">Tổng kết bài giảng</p>' +
      '<h2 class="s-title">Tóm tắt &amp; Tài liệu đọc thêm</h2>' +
      '<div class="g2" style="flex:1;gap:36px">' +
        '<div class="card" style="border-top:6px solid var(--luc)">' +
          '<h3>Trọng tâm kiến thức</h3>' +
          '<ul class="clean" style="gap:14px;margin-top:14px">' + pointsHtml + '</ul>' +
        '</div>' +
        '<div class="card" style="border-top:6px solid var(--lam)">' +
          '<h3>Tài liệu trích dẫn &amp; Tham khảo</h3>' +
          '<ul class="clean" style="gap:10px;margin-top:14px">' + refsHtml + '</ul>' +
          (sl.next ? '<p style="color:var(--son);font-weight:700;margin-top:20px;font-size:22px">' + esc(sl.next) + '</p>' : '') +
        '</div>' +
      '</div>';
  }

  // --- Nhận tín hiệu tương tác thời gian thực từ trang SV ---
  function setupLiveListener() {
    try {
      var ch = new BroadcastChannel('dkv_live_session');
      ch.onmessage = function(e) {
        var msg = e.data;
        if (!msg) return;
        var toast = document.createElement('div');
        toast.style.cssText = 'position:fixed;top:20px;right:20px;background:rgba(16,33,43,0.92);color:#fff;padding:12px 20px;border-radius:12px;z-index:9999;font-size:16px;box-shadow:0 8px 25px rgba(0,0,0,0.3);border-left:5px solid var(--luc-non);animation:sIn .3s ease;';
        
        if (msg.type === 'quiz') {
          toast.innerHTML = '⚡ <b>Sinh viên vừa trả lời:</b> Câu ' + (msg.data.qIdx + 1) + ' chọn <b>' + msg.data.choice + '</b> ' + (msg.data.correct ? '✔' : '✖');
        } else if (msg.type === 'group') {
          toast.innerHTML = '👥 <b>Ý kiến mới từ ' + esc(msg.data.group) + ':</b> ' + esc(msg.data.answer.substring(0, 45)) + '...';
        } else if (msg.type === 'qa') {
          toast.innerHTML = '💬 <b>Câu hỏi mới:</b> ' + esc(msg.data.q.substring(0, 50)) + '...';
        }
        document.body.appendChild(toast);
        setTimeout(function() { toast.remove(); }, 4000);
      };
    } catch(err) {}
  }

  // --- Kiểm tra Quiz ---
  window.checkQuizAnswer = function(btn, isCorrect) {
    var parent = btn.closest('.quiz-opts');
    if (!parent) return;
    parent.querySelectorAll('.quiz-opt-btn').forEach(function(b) {
      b.classList.remove('correct', 'wrong');
    });
    if (isCorrect) {
      btn.classList.add('correct');
    } else {
      btn.classList.add('wrong');
    }
  };

  // --- Điều khiển slide ---
  function nextSlide() {
    if (state.currentSlideIndex < state.slides.length - 1) {
      state.currentSlideIndex++;
      renderSlide();
    }
  }

  function prevSlide() {
    if (state.currentSlideIndex > 0) {
      state.currentSlideIndex--;
      renderSlide();
    }
  }

  function setupKeyboard() {
    window.addEventListener('keydown', function(e) {
      if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;
      switch (e.key) {
        case 'ArrowRight':
        case 'PageDown':
        case ' ':
          e.preventDefault();
          nextSlide();
          break;
        case 'ArrowLeft':
        case 'PageUp':
          e.preventDefault();
          prevSlide();
          break;
        case 'Home':
          e.preventDefault();
          state.currentSlideIndex = 0;
          renderSlide();
          break;
        case 'End':
          e.preventDefault();
          state.currentSlideIndex = state.slides.length - 1;
          renderSlide();
          break;
        case 'f':
        case 'F':
          toggleFullScreen();
          break;
        case 'o':
        case 'O':
          toggleModal('mOverview');
          break;
      }
    });

    document.querySelectorAll('[data-k="next"]').forEach(b => b.onclick = nextSlide);
    document.querySelectorAll('[data-k="prev"]').forEach(b => b.onclick = prevSlide);
    document.querySelectorAll('[data-k="overview"]').forEach(b => b.onclick = () => toggleModal('mOverview'));
    document.querySelectorAll('[data-k="full"]').forEach(b => b.onclick = toggleFullScreen);
  }

  function toggleFullScreen() {
    if (!document.fullscreenElement) {
      document.documentElement.requestFullscreen().catch(err => {});
    } else {
      if (document.exitFullscreen) document.exitFullscreen();
    }
  }

  function toggleModal(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.classList.toggle('on');
  }

  function updateOverviewModal() {
    var list = document.getElementById('ovList');
    if (!list) return;
    list.innerHTML = state.slides.map(function(s, i) {
      var isCur = (i === state.currentSlideIndex);
      return '<button type="button" class="overview-btn ' + (isCur ? 'cur' : '') + '" onclick="jumpToSlide(' + i + ')">' +
        '<b>Slide ' + (i + 1) + '</b>' +
        '<small>' + esc(s.title || s.type) + '</small>' +
      '</button>';
    }).join('');
  }

  window.jumpToSlide = function(idx) {
    state.currentSlideIndex = idx;
    renderSlide();
    toggleModal('mOverview');
  };

  // Đóng modal khi bấm ra ngoài hoặc nút close
  document.addEventListener('click', function(e) {
    if (e.target.hasAttribute('data-close') || e.target.classList.contains('modal')) {
      document.querySelectorAll('.modal').forEach(m => m.classList.remove('on'));
    }
  });

  // Khởi chạy khi DOM sẵn sàng
  document.addEventListener('DOMContentLoaded', init);
})();
