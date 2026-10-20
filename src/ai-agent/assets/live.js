(() => {
    'use strict';
    window.oyisoAgentReadStream = async (response, onEvent) => {
        const reader = response.body.getReader();
        const decoder = new TextDecoder('utf-8', { fatal: true });
        let buffer = '', result;
        function frame(source) {
            let event = 'message'; const data = [];
            for (const line of source.split(/\r\n|\n|\r/)) {
                if (line.startsWith('event:')) event = line.slice(6).trim();
                if (line.startsWith('data:')) data.push(line.slice(5).trimStart());
            }
            if (!data.length) return;
            const payload = JSON.parse(data.join('\n'));
            if (event === 'error') throw new Error(payload.message || '回复连接异常，请刷新对话重试。');
            if (!['state', 'text', 'done'].includes(event)) return;
            onEvent(event, payload);
            if (event === 'done') result = payload;
        }
        try {
            while (!result) {
                const chunk = await reader.read();
                buffer += decoder.decode(chunk.value, { stream: !chunk.done });
                if (buffer.length > 1048576) throw new Error('回复数据过大，请刷新对话查看。');
                let boundary;
                while ((boundary = /\r\n\r\n|\n\n|\r\r/.exec(buffer))) {
                    frame(buffer.slice(0, boundary.index));
                    buffer = buffer.slice(boundary.index + boundary[0].length);
                    if (result) break;
                }
                if (chunk.done) { if (!result && buffer.trim()) frame(buffer); break; }
            }
            if (!result) throw new Error('回复连接已断开，正在重新读取对话进度。');
            return result;
        } finally {
            await reader.cancel().catch(() => {});
            reader.releaseLock();
        }
    };
})();
