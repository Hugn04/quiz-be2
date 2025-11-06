<div style="height: 100%; width: 100%; background-color: #fff; padding: 20px 16px;">

    <div style="text-align: center; font-size: 20px;">Chào mừng bạn đến với HugnQuiz</div>

    <div style="display: flex;padding:16px 8px;" class='header-logo'>
        <img src='https://gshelper.com/wp-content/uploads/2017/09/Quiz-Logo.png' alt="Logo"
            style="width: 36px; height: 36px;">
        <div style="padding-left: 5px; font-size: 24px; font-weight: 600;" class='header-title'>HugnQuiz</div>
    </div>
    <b style="font-size: 20px; ">Xin chào,</b>
    <p>Chúng tôi vừa nhận được yêu cầu cung cấp mã OTP cho tài khoản của bạn.</p>
    <div style="display: flex">
        <p>Mã OTP của bạn là: </p>
        <div style="font-size: 24px; font-weight: 700; border-radius: 8px; background-color: #f0f8ff; letter-spacing: 10px; margin:0 8px; padding: 8px 16px;"
            class="otp">{{ $otp }}</div>
    </div>
    <p><b>Lưu ý:</b> Mã OTP này sẽ hết hạn sau 10 phút kể từ thời điểm bạn nhận được email
        này. Vui lòng không chia sẻ mã OTP này với bất kỳ ai để bảo vệ tài khoản của bạn.</p>
    <p>Nếu bạn không yêu cầu mã OTP này, có thể là do có người đã nhầm lẫn hoặc có thể có sự
        cố bảo mật. Trong trường hợp này, vui lòng bỏ qua email này và không thực hiện bất kỳ hành động nào. Nhưng
        nếu bạn cần một trang web để tạo câu hỏi thi bạn cũng có thể truy cập <a target="_blank"
            href="https://quiz.hunghytq.online">HugnQuiz</a> để tạo hoặc làm các đề thi !</p>
    <p>Trân trọng,</p>
    <p>Đội ngũ hỗ trợ</p>
    <p>Ngày {{ now() }}</p>
</div>
