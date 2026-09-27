(function () {
    'use strict';

    function parsePayload(panel) {
        if (!panel) return {};
        try {
            const payload = JSON.parse(panel.dataset.conditionalSecond || '{}');
            return payload && typeof payload === 'object' ? payload : {};
        } catch (error) {
            return {};
        }
    }

    function isMlV1(panel) {
        return String(panel && panel.dataset.probabilitySource || '') === 'ai_place_v1_joint120';
    }

    function buttonStyle(active) {
        return 'border:1px solid ' + (active ? '#a78bfa' : '#64748b')
            + ';background:' + (active ? '#312e81' : '#1e293b')
            + ';color:#f8fafc;border-radius:5px;padding:6px 8px;cursor:pointer;font-weight:700;font-size:12px;white-space:nowrap;';
    }

    function cell(text, css) {
        const td = document.createElement('td');
        td.textContent = text;
        td.style.cssText = css || 'padding:10px 8px;text-align:center;';
        return td;
    }

    function boatByCourse(data) {
        const result = {};
        Object.keys(data).forEach(function (key) {
            const block = data[key] || {};
            const headCourse = Number(block.head_course || key);
            const headBoat = Number(block.head_boat || 0);
            if (headCourse >= 1 && headCourse <= 6 && headBoat >= 1 && headBoat <= 6) {
                result[headCourse] = headBoat;
            }
            (Array.isArray(block.rows) ? block.rows : []).forEach(function (row) {
                const course = Number(row.second_course || 0);
                const boat = Number(row.second_boat || 0);
                if (course >= 1 && course <= 6 && boat >= 1 && boat <= 6) result[course] = boat;
            });
        });
        for (let course = 1; course <= 6; course++) {
            if (!result[course]) result[course] = course;
        }
        return result;
    }

    function render() {
        const target = document.getElementById('conditional-second-rate-panel');
        const source = document.getElementById('head1-exacta-panel');
        if (!target || !source) return false;

        const data = parsePayload(source);
        const heads = Object.keys(data)
            .map(Number)
            .filter(function (course) { return course >= 1 && course <= 6; })
            .sort(function (a, b) { return a - b; });
        if (!heads.length) return false;

        // 専用の2連単表示はデータ供給元としてDOMに残すが、画面では二重表示しない。
        source.style.display = 'none';

        const courseBoats = boatByCourse(data);
        const ml = isMlV1(source);
        let selected = heads.indexOf(1) >= 0 ? 1 : heads[0];
        let selectedSecond = 0;

        function draw() {
            const block = data[String(selected)] || {};
            const rowByCourse = {};
            (Array.isArray(block.rows) ? block.rows : []).forEach(function (row) {
                rowByCourse[Number(row.second_course)] = row;
            });

            target.innerHTML = '';
            const title = document.createElement('div');
            title.style.cssText = 'font-size:16px;font-weight:bold;color:#a78bfa;';
            title.textContent = '🤖 AI条件付き2着率' + (ml ? ' v1' : '');
            target.appendChild(title);

            const description = document.createElement('div');
            description.style.cssText = 'margin-top:4px;font-size:12px;color:#94a3b8;line-height:1.5;';
            description.textContent = '頭を選ぶと、その艇が1着になった場合の2着率を120通りから100%で表示します。';
            target.appendChild(description);

            const controls = document.createElement('div');
            controls.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;';
            heads.forEach(function (course) {
                const boat = Number(courseBoats[course] || course);
                const button = document.createElement('button');
                button.type = 'button';
                button.style.cssText = buttonStyle(course === selected);
                button.textContent = course + 'C ' + boat + '号艇';
                button.setAttribute('aria-pressed', course === selected ? 'true' : 'false');
                button.addEventListener('click', function () {
                    selected = course;
                    selectedSecond = 0;
                    draw();
                });
                controls.appendChild(button);
            });
            target.appendChild(controls);

            const current = document.createElement('div');
            current.style.cssText = 'margin-top:10px;font-size:13px;color:#c4b5fd;font-weight:700;';
            current.textContent = selected + 'C ' + Number(block.head_boat || courseBoats[selected] || selected) + '号艇が1着の場合';
            target.appendChild(current);

            const scroll = document.createElement('div');
            scroll.style.cssText = 'overflow-x:auto;margin-top:6px;';
            const table = document.createElement('table');
            table.style.cssText = 'width:100%;min-width:760px;border-collapse:collapse;';
            const thead = document.createElement('thead');
            const headRow = document.createElement('tr');
            headRow.style.backgroundColor = '#1e293b';
            headRow.appendChild(cell('項目 / 進入', 'padding:8px;text-align:left;min-width:130px;color:#f8fafc;'));
            for (let course = 1; course <= 6; course++) {
                const boat = Number(courseBoats[course] || course);
                headRow.appendChild(cell(course + 'コース / ' + boat + '号艇', 'padding:8px;text-align:center;min-width:95px;color:#f8fafc;font-weight:700;'));
            }
            thead.appendChild(headRow);
            table.appendChild(thead);

            const tbody = document.createElement('tbody');
            const rates = document.createElement('tr');
            rates.appendChild(cell('AI条件付き2着率' + (ml ? ' v1' : ''), 'padding:10px 8px;text-align:left;font-weight:700;color:#f8fafc;'));
            for (let course = 1; course <= 6; course++) {
                const row = rowByCourse[course];
                let label = '-';
                if (course !== selected && row && Number.isFinite(Number(row.ai))) {
                    const rank = Number(row.ai_rank || 0);
                    label = (Number(row.ai) * 100).toFixed(1) + '%' + (rank > 0 ? '（' + rank + '位）' : '');
                }
                rates.appendChild(cell(label, 'padding:10px 8px;text-align:center;font-size:17px;font-weight:700;color:#c4b5fd;border-top:1px solid #334155;'));
            }
            tbody.appendChild(rates);
            table.appendChild(tbody);
            scroll.appendChild(table);
            target.appendChild(scroll);

            const secondCourses = Object.keys(rowByCourse).map(Number).sort(function (a, b) { return a - b; });
            if (!rowByCourse[selectedSecond]) {
                selectedSecond = secondCourses.slice().sort(function (a, b) {
                    return Number(rowByCourse[b].ai) - Number(rowByCourse[a].ai);
                })[0] || 0;
            }
            if (selectedSecond > 0) {
                const secondTitle = document.createElement('div');
                secondTitle.style.cssText = 'margin-top:11px;font-size:13px;color:#c4b5fd;font-weight:700;';
                secondTitle.textContent = '2着を選択して3着候補を見る';
                target.appendChild(secondTitle);

                const secondControls = document.createElement('div');
                secondControls.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;';
                secondCourses.forEach(function (course) {
                    const boat = Number(courseBoats[course] || course);
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.style.cssText = buttonStyle(course === selectedSecond);
                    button.textContent = selected + '-' + course + '（' + boat + '号艇）';
                    button.addEventListener('click', function () {
                        selectedSecond = course;
                        draw();
                    });
                    secondControls.appendChild(button);
                });
                target.appendChild(secondControls);

                const thirdRows = (block.third_by_second_course || {})[String(selectedSecond)] || [];
                const thirdByCourse = {};
                thirdRows.forEach(function (row) { thirdByCourse[Number(row.third_course)] = row; });
                const thirdTitle = document.createElement('div');
                thirdTitle.style.cssText = 'margin-top:10px;font-size:13px;color:#5eead4;font-weight:700;';
                thirdTitle.textContent = 'AI条件付き3着率' + (ml ? ' v1' : '') + '：' + selected + '-' + selectedSecond + 'の場合';
                target.appendChild(thirdTitle);

                const thirdScroll = document.createElement('div');
                thirdScroll.style.cssText = 'overflow-x:auto;margin-top:6px;';
                const thirdTable = document.createElement('table');
                thirdTable.style.cssText = 'width:100%;min-width:760px;border-collapse:collapse;';
                const thirdBody = document.createElement('tbody');
                const thirdRateRow = document.createElement('tr');
                thirdRateRow.appendChild(cell('AI条件付き3着率' + (ml ? ' v1' : ''), 'padding:10px 8px;text-align:left;font-weight:700;color:#f8fafc;background:#1e293b;'));
                for (let course = 1; course <= 6; course++) {
                    const row = thirdByCourse[course];
                    let label = '-';
                    if (row && Number.isFinite(Number(row.ai))) {
                        label = (Number(row.ai) * 100).toFixed(1) + '%（' + Number(row.ai_rank || 0) + '位）';
                    }
                    thirdRateRow.appendChild(cell(label, 'padding:10px 8px;text-align:center;font-size:17px;font-weight:700;color:#5eead4;border-top:1px solid #334155;'));
                }
                thirdBody.appendChild(thirdRateRow);
                thirdTable.appendChild(thirdBody);
                thirdScroll.appendChild(thirdTable);
                target.appendChild(thirdScroll);
            }

            const note = document.createElement('div');
            note.style.cssText = 'margin-top:7px;font-size:11px;color:#94a3b8;line-height:1.5;';
            note.textContent = '2着率は P（2着 | ' + selected + 'Cが1着）、3着率は P（3着 | ' + selected + 'C-選択2着）。' + (ml
                ? ' 展示情報反映後は機械学習120通り v1 を使用。'
                : ' 展示情報取得前は従来の出目確率を使用。');
            target.appendChild(note);
        }

        draw();
        return true;
    }

    function schedule() {
        let tries = 100;
        function run() {
            if (render()) return;
            if (tries-- > 0) window.setTimeout(run, 50);
        }
        run();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', schedule);
    } else {
        schedule();
    }
})();
