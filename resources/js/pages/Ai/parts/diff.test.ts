import { lineDiff } from './diff';

test('lineDiff marca líneas agregadas, quitadas e iguales', () => {
    expect(lineDiff('a\nb\nc', 'a\nc\nd')).toEqual([
        { type: 'same', text: 'a' },
        { type: 'del', text: 'b' },
        { type: 'same', text: 'c' },
        { type: 'add', text: 'd' },
    ]);
    expect(lineDiff('x\r\ny', 'x\ny')).toEqual([{ type: 'same', text: 'x' }, { type: 'same', text: 'y' }]);
    expect(lineDiff('', 'nuevo')).toEqual([{ type: 'del', text: '' }, { type: 'add', text: 'nuevo' }]);
});
