#!/bin/bash
# MP3 파일들을 ffmpeg를 사용하여 ulaw 인코딩(raw 데이터)으로 변환 후 파일명을 .ulaw로 저장
# 현재 디렉토리의 모든 MP3 파일을 처리

for file in *.mp3; do
    # 파일 이름에서 .mp3 확장자 제거
    basename="${file%.mp3}"
    echo "변환 중: $file -> $basename.ulaw"

    # ffmpeg으로 ulaw 인코딩하여 raw 데이터 형태로 출력, 출력 컨테이너를 'mulaw'로 지정
    ffmpeg -i "$file" -ar 8000 -ac 1 -acodec pcm_mulaw -f mulaw "$basename.ulaw"

    # 변환 성공 여부 확인
    if [ $? -eq 0 ] && [ -f "$basename.ulaw" ]; then
        echo "성공: $basename.ulaw 생성됨"
    else
        echo "실패: $basename.ulaw 생성 실패"
    fi
done

echo "모든 파일 변환 완료!"